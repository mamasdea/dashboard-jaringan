<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Peran;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Pengguna')] class extends Component {
    use PasswordValidationRules, ProfileValidationRules, WithPagination;

    #[Url(as: 'q')]
    public string $cari = '';

    #[Locked]
    public ?int $idTarget = null;

    public string $name = '';
    public string $email = '';
    public string $peran = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Dijalankan pada setiap request ke komponen ini, termasuk pemanggilan
     * aksi secara langsung, bukan hanya saat halaman pertama dibuka.
     */
    public function boot(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatedCari(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function daftar(): LengthAwarePaginator
    {
        return User::query()
            ->when($this->cari !== '', function ($query) {
                $kata = '%'.addcslashes($this->cari, '%_\\').'%';

                $query->where(fn ($q) => $q->whereLike('name', $kata)->orWhereLike('email', $kata));
            })
            ->orderBy('name')
            ->paginate(15);
    }

    public function tambah(): void
    {
        $this->authorize('create', User::class);

        $this->kosongkanFormulir();
        $this->peran = Peran::Viewer->value;

        Flux::modal('formulir-pengguna')->show();
    }

    public function ubah(int $id): void
    {
        $target = User::findOrFail($id);

        $this->authorize('update', $target);

        $this->kosongkanFormulir();
        $this->idTarget = $target->id;
        $this->name = $target->name;
        $this->email = $target->email;
        $this->peran = $target->peran->value;

        Flux::modal('formulir-pengguna')->show();
    }

    public function simpan(): void
    {
        $target = $this->idTarget === null ? null : User::findOrFail($this->idTarget);

        $target === null
            ? $this->authorize('create', User::class)
            : $this->authorize('update', $target);

        $data = $this->validate([
            ...$this->profileRules($target?->id),
            'peran' => ['required', Rule::enum(Peran::class)],
            ...($target === null ? ['password' => $this->passwordRules()] : []),
        ]);

        $peran = Peran::from($data['peran']);

        if ($target !== null && $peran !== $target->peran && Gate::denies('ubahPeran', $target)) {
            $this->addError('peran', 'Anda tidak dapat mengubah peran akun sendiri.');

            return;
        }

        $pengguna = $target ?? new User;
        $pengguna->fill(collect($data)->only(['name', 'email', 'password'])->all());
        $pengguna->peran = $peran;
        $pengguna->save();

        $this->kosongkanFormulir();
        Flux::modal('formulir-pengguna')->close();
        Flux::toast(variant: 'success', text: $target === null ? 'Pengguna ditambahkan.' : 'Pengguna diperbarui.');
    }

    public function bukaKataSandi(int $id): void
    {
        $target = User::findOrFail($id);

        $this->authorize('update', $target);

        $this->kosongkanFormulir();
        $this->idTarget = $target->id;
        $this->email = $target->email;

        Flux::modal('kata-sandi-pengguna')->show();
    }

    public function simpanKataSandi(): void
    {
        $target = User::findOrFail($this->idTarget);

        $this->authorize('update', $target);

        $data = $this->validate(['password' => $this->passwordRules()]);

        $target->update(['password' => $data['password']]);

        if ($target->isNot(auth()->user())) {
            $target->akhiriSemuaSesi();
        }

        $this->kosongkanFormulir();
        Flux::modal('kata-sandi-pengguna')->close();
        Flux::toast(variant: 'success', text: 'Kata sandi diatur ulang.');
    }

    public function konfirmasiHapus(int $id): void
    {
        $target = User::findOrFail($id);

        $this->authorize('delete', $target);

        $this->kosongkanFormulir();
        $this->idTarget = $target->id;
        $this->name = $target->name;
        $this->email = $target->email;

        Flux::modal('hapus-pengguna')->show();
    }

    public function hapus(): void
    {
        $target = User::findOrFail($this->idTarget);

        $this->authorize('delete', $target);

        $target->akhiriSemuaSesi();
        $target->delete();

        $this->kosongkanFormulir();
        Flux::modal('hapus-pengguna')->close();
        Flux::toast(variant: 'success', text: 'Pengguna dihapus.');
    }

    private function kosongkanFormulir(): void
    {
        $this->reset('idTarget', 'name', 'email', 'peran', 'password', 'password_confirmation');
        $this->resetValidation();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Pengguna</flux:heading>
            <flux:subheading size="lg">Kelola akun dan peran pengguna dashboard.</flux:subheading>
        </div>

        @can('create', \App\Models\User::class)
            <flux:button variant="primary" icon="plus" wire:click="tambah" data-test="tambah-pengguna">
                Tambah pengguna
            </flux:button>
        @endcan
    </div>

    <div class="mb-4 max-w-sm">
        <flux:input wire:model.live.debounce.300ms="cari" icon="magnifying-glass" placeholder="Cari nama atau email" aria-label="Cari pengguna" />
    </div>

    <flux:table :paginate="$this->daftar">
        <flux:table.columns>
            <flux:table.column>Nama</flux:table.column>
            <flux:table.column>Email</flux:table.column>
            <flux:table.column>Peran</flux:table.column>
            <flux:table.column>2FA</flux:table.column>
            <flux:table.column>Dibuat</flux:table.column>
            <flux:table.column align="end">Tindakan</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->daftar as $pengguna)
                <flux:table.row :key="$pengguna->id">
                    <flux:table.cell variant="strong">
                        {{ $pengguna->name }}
                        @if ($pengguna->is(auth()->user()))
                            <flux:badge size="sm" class="ms-1">Anda</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $pengguna->email }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$pengguna->peran === \App\Enums\Peran::Administrator ? 'amber' : 'zinc'">
                            {{ $pengguna->peran->label() }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>{{ $pengguna->hasEnabledTwoFactorAuthentication() ? 'Aktif' : 'Belum' }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $pengguna->created_at?->timezone(config('app.display_timezone'))->format('d-m-Y') }}
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            @can('update', $pengguna)
                                <flux:button size="sm" variant="ghost" wire:click="ubah({{ $pengguna->id }})">Ubah</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="bukaKataSandi({{ $pengguna->id }})">Kata sandi</flux:button>
                            @endcan
                            @can('delete', $pengguna)
                                <flux:button size="sm" variant="ghost" wire:click="konfirmasiHapus({{ $pengguna->id }})">Hapus</flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">Tidak ada pengguna yang cocok.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="formulir-pengguna" class="w-full max-w-lg">
        <form wire:submit="simpan" class="space-y-6">
            <flux:heading size="lg">{{ $idTarget === null ? 'Tambah pengguna' : 'Ubah pengguna' }}</flux:heading>

            <flux:input wire:model="name" label="Nama" type="text" required autocomplete="off" />
            <flux:input wire:model="email" label="Email" type="email" required autocomplete="off" />

            <flux:select wire:model="peran" label="Peran" required>
                @foreach (\App\Enums\Peran::cases() as $pilihan)
                    <flux:select.option :value="$pilihan->value">
                        {{ $pilihan->label() }} — {{ $pilihan->keterangan() }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            @if ($idTarget === null)
                <flux:input wire:model="password" label="Kata sandi" type="password" required autocomplete="new-password" viewable />
                <flux:input wire:model="password_confirmation" label="Ulangi kata sandi" type="password" required autocomplete="new-password" viewable />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="simpan-pengguna">Simpan</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="kata-sandi-pengguna" class="w-full max-w-lg">
        <form wire:submit="simpanKataSandi" class="space-y-6">
            <div>
                <flux:heading size="lg">Atur ulang kata sandi</flux:heading>
                <flux:subheading>{{ $email }} akan dikeluarkan dari semua perangkat.</flux:subheading>
            </div>

            <flux:input wire:model="password" label="Kata sandi baru" type="password" required autocomplete="new-password" viewable />
            <flux:input wire:model="password_confirmation" label="Ulangi kata sandi baru" type="password" required autocomplete="new-password" viewable />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit">Simpan kata sandi</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="hapus-pengguna" class="w-full max-w-lg">
        <form wire:submit="hapus" class="space-y-6">
            <div>
                <flux:heading size="lg">Hapus pengguna?</flux:heading>
                <flux:subheading>
                    Akun {{ $name }} ({{ $email }}) akan dihapus dan tidak dapat masuk lagi.
                    Riwayatnya di log aktivitas tetap tersimpan.
                </flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Batal</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" type="submit" data-test="hapus-pengguna">Hapus pengguna</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
