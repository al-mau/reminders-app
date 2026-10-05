/*
 * PEMBUAT THUMBNAIL (gambar kecil halaman depan lampiran)
 *
 * Dipakai di dashboard.php (saat file diunggah) dan lampiran.php (untuk lampiran lama).
 * Thumbnail dibuat di browser dari file aslinya, lalu dikirim ke server sebagai JPEG kecil
 * (± 10-30 KB). Dengan begitu tabel Daftar Unit cukup memuat gambar kecil, bukan file utuh.
 *
 *  - Gambar (jpg, png, webp) : diperkecil
 *  - PDF                     : halaman pertama digambar dengan pdf.js (vendor/pdf.min.js)
 *  - Jenis lain              : tidak dibuat (tabel menampilkan ikon jenis file)
 *
 * Pemakaian: const jpeg = await buatThumbnail(fileAtauBlob, 'pdf');  // Blob atau null
 */
(function () {
    const LEBAR_MAKS  = 240; // piksel; cukup tajam untuk kotak thumbnail ±46 px di layar HP/retina
    const TINGGI_MAKS = 360;
    let pdfjsDimuat = null;

    // Muat pdf.js sekali saja, hanya saat ada PDF
    function muatPdfjs() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        if (!pdfjsDimuat) {
            pdfjsDimuat = new Promise((ok, gagal) => {
                const s = document.createElement('script');
                s.src = 'vendor/pdf.min.js';
                s.onload = () => {
                    window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'vendor/pdf.worker.min.js';
                    ok(window.pdfjsLib);
                };
                s.onerror = () => gagal(new Error('pdf.js gagal dimuat'));
                document.head.appendChild(s);
            });
        }
        return pdfjsDimuat;
    }

    function kanvasKeJpeg(kanvas) {
        return new Promise((ok) => kanvas.toBlob((b) => ok(b), 'image/jpeg', 0.72));
    }

    function kanvasPutih(lebar, tinggi) {
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(lebar));
        c.height = Math.max(1, Math.round(tinggi));
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, c.width, c.height);
        return [c, ctx];
    }

    async function dariGambar(blob) {
        const bmp = await createImageBitmap(blob);
        const skala = Math.min(1, LEBAR_MAKS / bmp.width, TINGGI_MAKS / bmp.height);
        const [c, ctx] = kanvasPutih(bmp.width * skala, bmp.height * skala);
        ctx.drawImage(bmp, 0, 0, c.width, c.height);
        if (bmp.close) bmp.close();
        return kanvasKeJpeg(c);
    }

    async function dariPdf(blob) {
        const pdfjs = await muatPdfjs();
        const data = new Uint8Array(await blob.arrayBuffer());
        // isEvalSupported: false -> pdf.js tidak menjalankan kode dari dalam file PDF (lebih aman)
        const dok = await pdfjs.getDocument({ data, isEvalSupported: false }).promise;
        try {
            const hal = await dok.getPage(1);
            const asli = hal.getViewport({ scale: 1 });
            const skala = Math.min(LEBAR_MAKS / asli.width, TINGGI_MAKS / asli.height);
            const vp = hal.getViewport({ scale: skala });
            const [c, ctx] = kanvasPutih(vp.width, vp.height);
            await hal.render({ canvasContext: ctx, viewport: vp }).promise;
            return kanvasKeJpeg(c);
        } finally {
            dok.destroy();
        }
    }

    window.buatThumbnail = async function (blob, ext) {
        ext = String(ext || '').toLowerCase();
        try {
            if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) return await dariGambar(blob);
            if (ext === 'pdf') return await dariPdf(blob);
        } catch (err) {
            console.warn('Thumbnail tidak dapat dibuat:', err);
        }
        return null; // gagal / jenis lain -> lampiran tetap tersimpan tanpa thumbnail
    };
})();
