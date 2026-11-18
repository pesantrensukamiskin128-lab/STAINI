import { createApp } from 'vue'
import AcademicToolsApp from './views/academic/AcademicToolsApp.vue'
import './academic-tools.css'

export function mount(element: Element, page: 'advisor' | 'krs') {
  const app = createApp(AcademicToolsApp, { page })
  app.mount(element)
  return () => app.unmount()
}
