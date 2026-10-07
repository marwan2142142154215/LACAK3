import { createApp } from 'vue'
import { createPinia } from 'pinia'
import VueApexCharts from 'vue3-apexcharts'
import { init, browserTracingIntegration } from '@sentry/vue'
import App from './App.vue'
import { router } from './router'
import './style.css'
import 'leaflet/dist/leaflet.css'

const sentryDsn = import.meta.env.VITE_SENTRY_DSN
if (sentryDsn) {
  init({
    dsn: sentryDsn,
    environment: import.meta.env.MODE,
    integrations: [browserTracingIntegration({ router })],
    tracesSampleRate: import.meta.env.PROD ? 0.1 : 1,
  })
}

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(VueApexCharts)
app.mount('#app')
