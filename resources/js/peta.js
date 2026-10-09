import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import '@geoman-io/leaflet-geoman-free';
import '@geoman-io/leaflet-geoman-free/dist/leaflet-geoman.css';
import '../css/peta.css';

/**
 * Peta jaringan (halaman /peta).
 *
 * Data diambil sekali dari konfig.urlData; filter dan pencarian berjalan di
 * browser. Perubahan koordinat dan jalur dikirim ke aksi Livewire, yang
 * memeriksa hak akses sendiri: tombol di sini hanya kenyamanan, bukan pengaman.
 */

const GAYA_JALUR = {
    backbone: { color: '#dc2626', weight: 5, opacity: 0.9 },
    distribusi: { color: '#2563eb', weight: 3, opacity: 0.9 },
    'kabel-lain': { color: '#71717a', weight: 3, opacity: 0.9, dashArray: '6 6' },
};

const LABEL_JENIS = {
    opd: 'OPD',
    perangkat: 'Perangkat utama',
    tiang: 'Tiang',
    odc: 'ODC',
    odp: 'ODP',
    closure: 'Closure',
    otb: 'Terminasi/OTB',
    backbone: 'Segmen kabel backbone',
    distribusi: 'Segmen kabel distribusi',
    'kabel-lain': 'Segmen kabel',
};

// Aset titik yang koordinatnya boleh digeser, dan nama jenisnya di server.
const JENIS_SERVER = { opd: 'opd', tiang: 'tiang', odc: 'titik', odp: 'titik', closure: 'titik', otb: 'titik' };

function elemen(tag, kelas, teks) {
    const el = document.createElement(tag);

    if (kelas) el.className = kelas;
    if (teks !== undefined && teks !== null) el.textContent = teks;

    return el;
}

function ikon(lapisan) {
    return L.divIcon({
        className: '',
        html: `<span class="peta-penanda peta-penanda-${lapisan}"></span>`,
        iconSize: [18, 18],
        // Perangkat utama digeser sedikit agar tidak menutupi penanda OPD-nya.
        iconAnchor: lapisan === 'perangkat' ? [-2, 20] : [9, 9],
        popupAnchor: [0, -10],
    });
}

function pasangPetaJaringan(akar, konfig, wire) {
    const kanvas = akar.querySelector('[data-peta-kanvas]');

    if (!kanvas || kanvas.dataset.terpasang) return;
    kanvas.dataset.terpasang = '1';

    const cari = (pemilih) => akar.querySelector(pemilih);
    const status = cari('[data-peta-status]');
    const tulisStatus = (pesan) => {
        if (status) status.textContent = pesan;
    };

    const peta = L.map(kanvas).setView(konfig.pusat, konfig.zoom);

    L.tileLayer(konfig.tile.url, {
        attribution: konfig.tile.atribusi,
        subdomains: konfig.tile.subdomain,
        maxZoom: konfig.tile.zoomMaks,
    }).addTo(peta);
    peta.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>');
    L.control.scale({ imperial: false }).addTo(peta);

    /** @type {Array<{lapisan: string, data: object, layer: L.Layer|null}>} */
    const butir = [];
    const lapisanAktif = new Set();
    let opdDipilih = '';
    let modeUbah = false;

    const kotakLapisan = [...akar.querySelectorAll('[data-peta-lapisan] input[type=checkbox]')];
    kotakLapisan.forEach((kotak) => kotak.checked && lapisanAktif.add(kotak.value));

    function isiPopup(b) {
        const wadah = elemen('div', 'peta-popup');

        wadah.append(elemen('div', 'peta-popup-jenis', LABEL_JENIS[b.lapisan]));

        const judul = elemen('div', 'peta-popup-kode', b.data.kode);
        if (b.data.simulasi) judul.append(' ', elemen('span', 'peta-popup-lencana', 'SIMULASI'));
        wadah.append(judul);

        if (b.data.nama) wadah.append(elemen('div', null, b.data.nama));
        if (b.data.keterangan) wadah.append(elemen('div', 'peta-popup-keterangan', b.data.keterangan));
        if (b.data.perlu_dilengkapi) wadah.append(elemen('div', 'peta-popup-peringatan', 'Perlu dilengkapi'));
        if (b.data.posisi_dari && b.data.posisi_dari !== 'sendiri') {
            wadah.append(elemen('div', 'peta-popup-keterangan', `Posisi mengikuti ${b.data.posisi_dari === 'tiang' ? 'tiang' : 'OPD'} tempatnya terpasang.`));
        }

        const tautan = elemen('a', 'peta-popup-tautan', 'Buka halaman detail');
        tautan.href = b.data.url;
        wadah.append(tautan);

        return wadah;
    }

    function terlihat(b) {
        return b.layer !== null
            && lapisanAktif.has(b.lapisan)
            && (opdDipilih === '' || b.data.opd_ids.includes(Number(opdDipilih)));
    }

    function terapkanFilter() {
        butir.forEach((b) => {
            if (b.layer === null) return;

            if (terlihat(b)) {
                if (!peta.hasLayer(b.layer)) b.layer.addTo(peta);
            } else if (peta.hasLayer(b.layer)) {
                peta.removeLayer(b.layer);
            }
        });

        terapkanModeUbah();
    }

    async function simpan(aksi, argumen, batal) {
        try {
            const hasil = await wire.call(aksi, ...argumen);

            tulisStatus(hasil.pesan);
            if (!hasil.ok && batal) batal();

            return hasil.ok;
        } catch {
            tulisStatus('Perubahan tidak tersimpan. Anda mungkin tidak berhak mengubah aset ini.');
            if (batal) batal();

            return false;
        }
    }

    function terapkanModeUbah() {
        butir.forEach((b) => {
            if (b.layer === null || !peta.hasLayer(b.layer)) return;

            if (b.layer instanceof L.Marker && JENIS_SERVER[b.lapisan]) {
                modeUbah ? b.layer.dragging.enable() : b.layer.dragging.disable();
            } else if (b.layer instanceof L.Polyline) {
                modeUbah ? b.layer.pm.enable({ allowSelfIntersection: true }) : b.layer.pm.disable();
            }
        });
    }

    function buatPenanda(b) {
        const penanda = L.marker([b.data.lat, b.data.lng], { icon: ikon(b.lapisan), title: b.data.kode, keyboard: true });

        penanda.bindPopup(() => isiPopup(b));

        if (JENIS_SERVER[b.lapisan]) {
            let asal = penanda.getLatLng();

            penanda.on('dragstart', () => {
                asal = penanda.getLatLng();
            });
            penanda.on('dragend', () => {
                const { lat, lng } = penanda.getLatLng();

                simpan('simpanKoordinat', [JENIS_SERVER[b.lapisan], b.data.id, lat, lng], () => penanda.setLatLng(asal)).then((ok) => {
                    if (!ok) return;

                    b.data.lat = lat;
                    b.data.lng = lng;
                    b.data.posisi_dari = 'sendiri';
                });
            });
        }

        return penanda;
    }

    function buatJalur(b) {
        const garis = L.polyline(b.data.jalur, GAYA_JALUR[b.lapisan]);
        let tunda = null;

        garis.bindPopup(() => isiPopup(b));
        garis.on('pm:edit', () => {
            clearTimeout(tunda);
            tunda = setTimeout(() => {
                const titik = garis.getLatLngs().map((t) => [t.lat, t.lng]);

                simpan('simpanJalur', [b.data.id, titik]).then((ok) => {
                    if (ok) b.data.jalur = titik;
                });
            }, 700);
        });

        return garis;
    }

    function sorot(b) {
        if (b.layer === null) {
            tulisStatus(`${b.data.kode} belum memiliki koordinat, jadi tidak digambar di peta.`);

            return;
        }

        // Pastikan aset yang dicari tidak tersembunyi oleh filter.
        lapisanAktif.add(b.lapisan);
        kotakLapisan.filter((kotak) => kotak.value === b.lapisan).forEach((kotak) => (kotak.checked = true));

        if (opdDipilih !== '' && !b.data.opd_ids.includes(Number(opdDipilih))) {
            opdDipilih = '';
            cari('[data-peta-opd]').value = '';
        }

        terapkanFilter();

        if (b.layer instanceof L.Polyline) {
            peta.fitBounds(b.layer.getBounds(), { padding: [40, 40], maxZoom: 18 });
        } else {
            peta.setView(b.layer.getLatLng(), Math.max(peta.getZoom(), 18));
        }

        b.layer.openPopup();
    }

    function pasangPencarian() {
        const masukan = cari('[data-peta-cari]');
        const daftar = cari('[data-peta-hasil]');

        if (!masukan || !daftar) return;

        masukan.addEventListener('input', () => {
            const kata = masukan.value.trim().toLowerCase();

            daftar.replaceChildren();
            daftar.classList.toggle('hidden', kata === '');

            if (kata === '') return;

            const cocok = butir
                .filter((b) => b.data.kode.toLowerCase().includes(kata) || (b.data.nama ?? '').toLowerCase().includes(kata))
                .sort((a, b) => Number(b.data.kode.toLowerCase().startsWith(kata)) - Number(a.data.kode.toLowerCase().startsWith(kata)))
                .slice(0, 12);

            if (cocok.length === 0) {
                daftar.append(elemen('li', 'px-3 py-2 text-zinc-500', 'Tidak ada aset yang cocok.'));

                return;
            }

            cocok.forEach((b) => {
                const baris = elemen('li');
                const tombol = elemen('button', 'peta-hasil-tombol');

                tombol.type = 'button';
                tombol.append(
                    elemen('span', 'font-medium', b.data.kode),
                    elemen('span', 'text-zinc-500', ` · ${LABEL_JENIS[b.lapisan]}${b.layer === null ? ' · tanpa koordinat' : ''}`),
                );
                tombol.addEventListener('click', () => sorot(b));
                baris.append(tombol);

                if (b.layer === null) {
                    const tautan = elemen('a', 'peta-hasil-tautan', 'Buka detail');
                    tautan.href = b.data.url;
                    baris.append(tautan);
                }

                daftar.append(baris);
            });
        });
    }

    function pasangPenggambaran() {
        const panel = cari('[data-peta-panel-gambar]');
        const pilihan = cari('[data-peta-segmen]');
        const tombol = cari('[data-peta-gambar]');

        if (!panel || !pilihan || !tombol) return;

        const isiPilihan = () => {
            const tanpaJalur = butir.filter((b) => b.data.jalur !== undefined && b.layer === null);

            pilihan.replaceChildren(...tanpaJalur.map((b) => {
                const opsi = elemen('option', null, `${b.data.kode} (${b.data.keterangan})`);
                opsi.value = String(b.data.id);

                return opsi;
            }));
            tombol.disabled = tanpaJalur.length === 0;

            if (tanpaJalur.length === 0) {
                pilihan.append(elemen('option', null, 'Semua segmen sudah punya jalur'));
            }
        };

        isiPilihan();

        cari('[data-peta-mode-ubah]')?.addEventListener('change', () => panel.classList.toggle('hidden', !modeUbah));

        tombol.addEventListener('click', () => {
            const sasaran = butir.find((b) => b.data.jalur !== undefined && String(b.data.id) === pilihan.value);

            if (!sasaran) return;

            tulisStatus(`Menggambar jalur ${sasaran.data.kode}. Klik titik terakhir untuk selesai.`);
            peta.pm.enableDraw('Line', { templineStyle: GAYA_JALUR[sasaran.lapisan], hintlineStyle: GAYA_JALUR[sasaran.lapisan] });

            // Penggambaran sebelumnya yang dibatalkan tidak boleh ikut tersimpan.
            peta.off('pm:create');
            peta.once('pm:create', (kejadian) => {
                const titik = kejadian.layer.getLatLngs().map((t) => [t.lat, t.lng]);

                peta.removeLayer(kejadian.layer);
                peta.pm.disableDraw();

                simpan('simpanJalur', [sasaran.data.id, titik]).then((ok) => {
                    if (!ok) return;

                    sasaran.data.jalur = titik;
                    sasaran.layer = buatJalur(sasaran);
                    terapkanFilter();
                    isiPilihan();
                    hitungJumlah();
                });
            });
        });
    }

    function hitungJumlah() {
        akar.querySelectorAll('[data-peta-jumlah]').forEach((el) => {
            el.textContent = String(butir.filter((b) => b.lapisan === el.dataset.petaJumlah && b.layer !== null).length);
        });
    }

    kotakLapisan.forEach((kotak) => kotak.addEventListener('change', () => {
        kotak.checked ? lapisanAktif.add(kotak.value) : lapisanAktif.delete(kotak.value);
        terapkanFilter();
    }));

    cari('[data-peta-opd]')?.addEventListener('change', (kejadian) => {
        opdDipilih = kejadian.target.value;
        terapkanFilter();
    });

    cari('[data-peta-mode-ubah]')?.addEventListener('change', (kejadian) => {
        modeUbah = konfig.bolehUbah && kejadian.target.checked;
        tulisStatus(modeUbah ? 'Mode ubah aktif: geser penanda atau titik jalur. Perubahan langsung disimpan.' : 'Mode ubah dimatikan.');
        terapkanModeUbah();
    });

    fetch(konfig.urlData, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then((jawaban) => {
            if (!jawaban.ok) throw new Error(String(jawaban.status));

            return jawaban.json();
        })
        .then((data) => {
            data.opd.forEach((d) => butir.push({ lapisan: 'opd', data: d, layer: null }));
            data.perangkat.forEach((d) => butir.push({ lapisan: 'perangkat', data: d, layer: null }));
            data.tiang.forEach((d) => butir.push({ lapisan: 'tiang', data: d, layer: null }));
            data.titik.forEach((d) => butir.push({ lapisan: d.jenis, data: d, layer: null }));
            data.segmen.forEach((d) => butir.push({ lapisan: d.klasifikasi ?? 'kabel-lain', data: d, layer: null }));

            butir.forEach((b) => {
                if (b.data.jalur !== undefined) {
                    b.layer = b.data.jalur.length >= 2 ? buatJalur(b) : null;
                } else {
                    b.layer = b.data.lat !== null && b.data.lng !== null ? buatPenanda(b) : null;
                }
            });

            terapkanFilter();
            hitungJumlah();
            pasangPencarian();
            pasangPenggambaran();

            const digambar = butir.filter((b) => b.layer !== null);
            const tanpaPosisi = butir.length - digambar.length;

            if (digambar.length > 0) {
                peta.fitBounds(L.featureGroup(digambar.map((b) => b.layer)).getBounds(), { padding: [30, 30], maxZoom: 17 });
            }

            tulisStatus(`${digambar.length} aset digambar di peta.`);

            const catatan = cari('[data-peta-tanpa-posisi]');

            if (catatan && tanpaPosisi > 0) {
                catatan.textContent = `${tanpaPosisi} aset belum memiliki koordinat atau jalur.`;
                catatan.classList.remove('hidden');
            }

            if (konfig.fokus) {
                const [jenis, kode] = konfig.fokus.split(':');
                const sasaran = butir.find((b) => b.data.kode === kode && (
                    jenis === 'kabel' ? b.data.jalur !== undefined : jenis === 'titik' ? JENIS_SERVER[b.lapisan] === 'titik' : b.lapisan === jenis
                ));

                if (sasaran) {
                    sorot(sasaran);
                } else {
                    tulisStatus(`${kode} tidak digambar di peta (hanya OPD, switch utama, tiang, titik jaringan, dan kabel yang tampil).`);
                }
            }
        })
        .catch(() => tulisStatus('Data peta tidak dapat dimuat. Muat ulang halaman.'));

    // Ukuran wadah baru pasti setelah tata letak selesai (mis. setelah wire:navigate).
    setTimeout(() => peta.invalidateSize(), 0);
}

window.pasangPetaJaringan = pasangPetaJaringan;
window.dispatchEvent(new Event('peta:siap'));
