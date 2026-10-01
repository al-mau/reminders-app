// Service Worker: halaman PHP selalu diambil dari jaringan (data harus real-time),
// cache hanya dipakai untuk aset statis CDN dan sebagai cadangan saat offline.
// Ganti angka versi (v2 -> v3) bila daftar aset diubah, agar cache lama dibuang
const CACHE_NAME = 'reminders-app-v2';
const STATIC_ASSETS = [
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'
];

// Saat pertama dipasang: simpan aset CDN ke cache
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(STATIC_ASSETS))
      .catch(() => {})
      .then(() => self.skipWaiting())
  );
});

// Saat versi baru aktif: hapus cache versi lama
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// Setiap permintaan dari browser lewat sini; POST (simpan data, upload) tidak disentuh
self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  // Halaman (navigasi): network-first, jangan di-cache karena berisi data login
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() =>
        new Response('<h3 style="font-family:sans-serif;text-align:center;margin-top:40px">Anda sedang offline</h3>', {
          headers: { 'Content-Type': 'text/html; charset=utf-8' }
        })
      )
    );
    return;
  }

  // Aset statis: cache-first
  if (STATIC_ASSETS.includes(request.url)) {
    event.respondWith(caches.match(request).then((res) => res || fetch(request)));
  }
});
