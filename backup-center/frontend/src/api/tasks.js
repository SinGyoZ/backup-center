import http from '@/utils/http'

export const tasksApi = {
  list(params = {}) {
    return http.get('/tasks', { params }).then((r) => r.data)
  },
  get(id) {
    return http.get(`/tasks/${id}`).then((r) => r.data.data)
  },
  create(payload) {
    return http.post('/tasks', payload).then((r) => r.data.data)
  },
  update(id, payload) {
    return http.put(`/tasks/${id}`, payload).then((r) => r.data.data)
  },
  destroy(id) {
    return http.delete(`/tasks/${id}`).then((r) => r.data)
  },
  syncNow(id) {
    return http.post(`/tasks/${id}/sync-now`).then((r) => r.data)
  },
  packNow(id) {
    return http.post(`/tasks/${id}/pack-now`).then((r) => r.data)
  },
  incrementalBackupNow(id) {
    return http.post(`/tasks/${id}/incremental-backup`).then((r) => r.data)
  },
}

export const systemApi = {
  backupNow() {
    return http.post('/system/backup', {}, { timeout: 180000 }).then((r) => r.data)
  },
}
