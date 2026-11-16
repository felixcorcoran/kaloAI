/**
 * Service worker de KaloAI: permite instalar la web como app y que, sin
 * conexión, se vea una página de aviso en vez del error del navegador.
 *
 * Solo se guardan en caché los archivos estáticos (estilos, scripts, iconos).
 * Las páginas con datos personales nunca se guardan: siempre se piden a la red.
 */
const VERSION = 'kaloai-v2';
const OFFLINE = 'offline.html';
const PRECARGA = [OFFLINE, 'public/icons/icono-192.png', 'public/fonts/dm-sans.woff2', 'public/css/fuentes.css'];

self.addEventListener('install', (evento) => {
    evento.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(PRECARGA)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil(
        caches.keys()
            .then((claves) => Promise.all(claves.filter((c) => c !== VERSION).map((c) => caches.delete(c))))
            .then(() => self.clients.claim())
    );
});

const esEstatico = (url) => url.origin === self.location.origin && /\.(css|js|png|jpg|jpeg|svg|webp|woff2?)$/.test(url.pathname);

self.addEventListener('fetch', (evento) => {
    const peticion = evento.request;
    if (peticion.method !== 'GET') return;
    const url = new URL(peticion.url);

    // Páginas: siempre de la red; si no hay conexión, la página de aviso
    if (peticion.mode === 'navigate') {
        evento.respondWith(fetch(peticion).catch(() => caches.match(OFFLINE)));
        return;
    }

    // Fuentes y librerías con la versión en el nombre: nunca cambian, así que
    // primero la copia guardada (con mala conexión no se espera a la red)
    if (url.origin === self.location.origin && /\/public\/(fonts|lib)\//.test(url.pathname)) {
        evento.respondWith(
            caches.match(peticion).then((guardada) => guardada || fetch(peticion).then((respuesta) => {
                if (respuesta.ok && !(respuesta.headers.get('Content-Type') || '').includes('text/html')) {
                    const copia = respuesta.clone();
                    caches.open(VERSION).then((cache) => cache.put(peticion, copia));
                }
                return respuesta;
            }))
        );
        return;
    }

    // Estáticos: primero la red (así llegan los cambios) y, sin conexión, la copia guardada
    if (esEstatico(url)) {
        evento.respondWith(
            fetch(peticion).then((respuesta) => {
                const tipo = respuesta.headers.get('Content-Type') || '';
                // Solo se guarda lo que de verdad es un estático (no una página de error del hosting)
                if (respuesta.ok && !tipo.includes('text/html')) {
                    const copia = respuesta.clone();
                    caches.open(VERSION).then((cache) => cache.put(peticion, copia));
                }
                return respuesta;
            }).catch(() => caches.match(peticion))
        );
    }
});
