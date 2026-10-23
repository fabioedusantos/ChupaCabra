/* ChupaCabra PWA: only public, versioned assets and an offline notice. */
const CACHE = 'chupacabra-static-20261023-v2'
const OFFLINE_URL = '/offline.html'

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_URL, '/branding/ChupaCabra-horizontal-fundo-claro.svg'])))
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys()
    await Promise.all(keys.filter((key) => key.startsWith('chupacabra-static-') && key !== CACHE).map((key) => caches.delete(key)))
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  if (request.method !== 'GET') return
  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return
  // Authentication, shopping data and other API responses must NEVER be cached.
  if (url.pathname.startsWith('/api/')) return

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(async () => {
      return (await caches.match(OFFLINE_URL)) || Response.error()
    }))
    return
  }

  // Vite assets have content-hashed filenames; safe to cache without user data.
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(caches.open(CACHE).then(async (cache) => {
      const cached = await cache.match(request)
      if (cached) return cached
      const response = await fetch(request)
      if (response.ok) await cache.put(request, response.clone())
      return response
    }))
  }
})
