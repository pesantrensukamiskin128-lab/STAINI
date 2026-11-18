import { createApp } from 'vue'
import ClassScheduleEditor from './views/academic/ClassScheduleEditor.vue'
import './academic-tools.css'
import './class-schedule.css'

export function mount(element: Element) {
  const app = createApp(ClassScheduleEditor)
  app.mount(element)
  return () => app.unmount()
}
