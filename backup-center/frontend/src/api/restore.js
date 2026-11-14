import http from '@/utils/http'

/**
 * 数据恢复（P2 独立栏目）：
 *   tasks()    任务筛选列表（含最近备份信息，用于默认选中与空态判定）
 *   backups()  按任务 + 日期分组的备份套件（每组 = 一套，组内文件按备份时间倒序）
 *   restore()   整套批量下发（body: { backup_log_ids: [] }）
 *   status()    多文件恢复状态轮询（按 backup_log_id 维度，无命令的 id 不返回）
 */
export const restoreApi = {
  tasks() {
    return http.get('/restore/tasks').then((r) => r.data)
  },
  backups(params) {
    return http.get('/restore/backups', { params }).then((r) => r.data)
  },
  restore(payload) {
    return http.post('/restore', payload).then((r) => r.data)
  },
  status(ids) {
    return http.get('/restore/status', { params: { backup_log_ids: ids } }).then((r) => r.data)
  },
}
