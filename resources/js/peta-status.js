import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import '../css/peta-status.css';

/**
 * Peta status di dashboard.
 *
 * Hanya menampilkan status yang sudah tersimpan di server (konfig.urlData) dan
 * mengambilnya ulang secara berkala. Tidak ada pemeriksaan jaringan dari sini.
 * Status tiap penanda terbaca dari huruf di dalamnya, bukan hanya dari warna.
 */

function elemen(tag, kelas, teks) {
    const el = document.createElement(tag);

    if (kelas) el.className = kelas;
    if (teks !== undefined && teks !== null) el.textContent = teks;

    return el;
}

function ikon(opd) {
    return L.divIcon({
        className: '',
        html: `<span class="peta-status-penanda peta-status-${opd.status}"></span>`,
        iconSize: [26, 26],
        iconAnchor: [13, 13],
        popupAnchor: [0, -14],
    });
}

function isiPopup(opd) {
    const wadah = elemen('div', 'peta-status-popup');

    const judul = elemen('div', 'peta-status-popup-nama', opd.nama);
    if (opd.simulasi) judul.append(' ', elemen('span', 'peta-status-popup-lencana', 'SIMULASI'));
    wadah.append(judul);

    wadah.append(elemen('div', 'peta-status-popup-status', `Status: ${opd.label}`));
    if (opd.keterangan) wadah.append(elemen('div', null, opd.keterangan));
    wadah.append(elemen('div', null, opd.diperiksa ? `Diperiksa terakhir ${opd.diperiksa} WIB` : 'Belum pernah diperiksa'));

    const tautan = elemen('a', 'peta-status-popup-tautan', 'Lihat detail OPD');
    tautan.href = opd.url;
    wadah.append(tautan);

    return wadah;
}

function pasangPetaStatus(akar, konfig) {
    const kanvas = akar.querySelector('[data-peta-status]');

    if (!kanvas || kanvas.dataset.terpasang) return;
    kanvas.dataset.terpasang = '1';

    const catatan = akar.querySelector('[data-peta-status-catatan]');
    const tulis = (pesan) => {
        if (catatan) catatan.textContent = pesan;
    };

    const peta = L.map(kanvas, { scrollWheelZoom: false }).setView(konfig.pusat, konfig.zoom);

    L.tileLayer(konfig.tile.url, {
        attribution: konfig.tile.atribusi,
        subdomains: konfig.tile.subdomain,
        maxZoom: konfig.tile.zoomMaks,
    }).addTo(peta);
    peta.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>');

    const lapisan = L.layerGroup().addTo(peta);
    let sudahDipaskan = false;

    async function muat() {
        // Halaman sudah ditinggalkan (wire:navigate): berhenti mengambil data.
        if (!document.body.contains(kanvas)) {
            clearInterval(pewaktu);

            return;
        }

        try {
            const jawaban = await fetch(konfig.urlData, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

            if (!jawaban.ok) throw new Error(String(jawaban.status));

            const data = await jawaban.json();
            const berposisi = data.opd.filter((opd) => opd.lat !== null && opd.lng !== null);

            lapisan.clearLayers();

            berposisi.forEach((opd) => {
                const penanda = L.marker([opd.lat, opd.lng], {
                    icon: ikon(opd),
                    title: `${opd.nama}: ${opd.label}`,
                    alt: `${opd.nama}: ${opd.label}`,
                    keyboard: true,
                });

                penanda.on('add', () => {
                    // Huruf status ditulis sebagai teks, bukan HTML.
                    const isi = penanda.getElement()?.querySelector('.peta-status-penanda');
                    if (isi) isi.textContent = opd.kode_status;
                });
                penanda.bindPopup(() => isiPopup(opd));
                penanda.addTo(lapisan);
            });

            if (!sudahDipaskan && berposisi.length > 0) {
                peta.fitBounds(L.latLngBounds(berposisi.map((opd) => [opd.lat, opd.lng])), { padding: [30, 30], maxZoom: 16 });
                sudahDipaskan = true;
            }

            const tanpaPosisi = data.opd.length - berposisi.length;

            tulis(`${berposisi.length} OPD di peta, data pukul ${data.dibuat_pada} WIB.` + (tanpaPosisi > 0 ? ` ${tanpaPosisi} OPD belum memiliki koordinat.` : ''));
        } catch {
            tulis('Data peta tidak dapat dimuat. Tabel di bawah tetap menampilkan status terakhir.');
        }
    }

    muat();

    const pewaktu = setInterval(muat, Math.max(10, konfig.segarkanDetik) * 1000);

    setTimeout(() => peta.invalidateSize(), 0);
}

window.pasangPetaStatus = pasangPetaStatus;
window.dispatchEvent(new Event('peta-status:siap'));
