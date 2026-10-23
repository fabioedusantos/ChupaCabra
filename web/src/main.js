import { createApp } from 'vue'
import App from './App.vue'
import './style.css'
createApp(App).mount('#app')

// Progressive Web App: register only in a published production build.
// Service Worker never caches authenticated /api requests.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch((err) => {
      console.warn('PWA: service worker não pôde ser ativado', err)
    })
  })
}
