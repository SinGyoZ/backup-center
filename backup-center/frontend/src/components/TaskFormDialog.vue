<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Check, FolderOpened, Box, Files } from '@element-plus/icons-vue'
import { tasksApi } from '@/api/tasks'
import { serversApi } from '@/api/servers'
import { storagesApi } from '@/api/storages'
import BackupPathsEditor from './BackupPathsEditor.vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  task: { type: Object, default: null },
})
const emit = defineEmits(['update:modelValue', 'saved'])

const isEdit = computed(() => !!props.task?.id)
const title = computed(() => (isEdit.value ? '编辑任务' : '添加任务'))

const formRef = ref(null)
const saving = ref(false)
const serverOptions = ref([])
const storageOptions = ref([])
const loadingServers = ref(false)

const emptyBackupPath = (idx = 0) => ({
  label: `备份目录${idx + 1}`,
  path: '',
  pattern: '',
})

const form = reactive({
  server_id: null,
  name: '',
  backup_paths: [emptyBackupPath(0)],
  retention_days: 30,
  status: 'enabled',
  remark: '',
  storage_ids: [],

  // 同步方式：auto=手动/Agent 自动扫描；scheduled=按 schedule_time 每天派发一次指令
  sync_mode: 'auto',
  schedule_time: '03:00',

  // 备份来源：external=收集目录里的现成归档；self=由 Agent 主动打包/快照
  backup_source: 'external',
  // 备份方式：full=全量归档；incremental=rsync 硬链快照（仅 backup_source=self 可选）
  backup_mode: 'full',
  // 目录快照目标存储（单值；必须是 SFTP + SSH 私钥 + 未加密存储，由 incrementalStorageOptions 约束）
  incremental_storage_id: null,
  // 恢复模式：lite=留档恢复（下载回原目录手动处理）；advanced=自动恢复（解压覆盖备份时记录的源目录）
  restore_mode: 'lite',
})

/* ---------- 三种备份方式（前端单一概念）↔ 后端两字段映射 ----------
 *   simple      归档收集 → backup_source=external + backup_mode=full      （扫描目录里的现成归档文件）
 *   advanced    目录打包 → backup_source=self     + backup_mode=full      （Agent 对每个目录 tar.gz 打包）
 *   incremental 目录快照 → backup_source=self     + backup_mode=incremental（rsync 硬链增量快照）
 * 数据层仍是 backup_source × backup_mode 两字段，后端契约零改动；
 * 这里收敛为单一「方式」概念，切换时同步复位依赖字段（离开快照方式清空目标存储）。 */
const modeCards = [
  { key: 'simple', name: '归档收集', method: '扫描归档', desc: '监控服务器目录里的现成归档文件，发现新文件即上传', icon: FolderOpened },
  { key: 'advanced', name: '目录打包', method: 'Agent 打包', desc: 'Agent 对任务配置的每个目录执行 tar.gz 打包后上传', icon: Box },
  { key: 'incremental', name: '目录快照', method: 'rsync 快照', desc: 'rsync 硬链快照到 VPS，仅传输变化部分', icon: Files },
]

const backupModeKey = computed({
  get: () => {
    if (form.backup_source !== 'self') return 'simple'
    return form.backup_mode === 'incremental' ? 'incremental' : 'advanced'
  },
  set: (key) => {
    if (key === 'simple') {
      form.backup_source = 'external'
      form.backup_mode = 'full'
      form.incremental_storage_id = null
    } else if (key === 'advanced') {
      form.backup_source = 'self'
      form.backup_mode = 'full'
      form.incremental_storage_id = null
    } else {
      form.backup_source = 'self'
      form.backup_mode = 'incremental'
    }
  },
})

const selectMode = (key) => {
  backupModeKey.value = key
}

const rules = {
  server_id: [{ required: true, message: '请选择所属服务器', trigger: 'change' }],
  name: [{ required: true, message: '请输入任务名称', trigger: 'blur' }],
  retention_days: [{ required: true, message: '请输入保留天数', trigger: 'blur' }],
}

const loadServers = async () => {
  loadingServers.value = true
  try {
    const res = await serversApi.list({ per_page: 100 })
    serverOptions.value = res.data || []
  } finally {
    loadingServers.value = false
  }
}

const loadStorages = async () => {
  const res = await storagesApi.list({ per_page: 100, status: 'enabled' })
  storageOptions.value = res.data || []
}

const mandatoryStorages = computed(() =>
  storageOptions.value.filter((s) => s.is_mandatory),
)
const optionalStorages = computed(() =>
  storageOptions.value.filter((s) => ! s.is_mandatory),
)
// 目录快照目标候选：对象存储无增量语义，仅 SFTP + SSH 私钥 + 未加密；
// private_key_set / encryption_enabled 为后端脱敏布尔字段（StorageResource）
const incrementalStorageOptions = computed(() =>
  storageOptions.value.filter((s) => s.type === 'sftp' && s.private_key_set && ! s.encryption_enabled),
)

onMounted(() => { loadServers(); loadStorages() })

watch(
  () => props.modelValue,
  (v) => {
    if (v) {
      // 重置
      Object.assign(form, {
        server_id: null,
        name: '',
        backup_paths: [emptyBackupPath(0)],
        retention_days: 30,
        status: 'enabled',
        remark: '',
        storage_ids: [],
        sync_mode: 'auto',
        schedule_time: '03:00',
        backup_source: 'external',
        backup_mode: 'full',
        incremental_storage_id: null,
        restore_mode: 'lite',
      })
      if (props.task) {
        const existing = Array.isArray(props.task.backup_paths) ? props.task.backup_paths : []
        const paths = existing.length > 0
          ? existing.map((p, i) => ({
              label: p.label || `备份目录${i + 1}`,
              path: p.path || '',
              pattern: p.pattern || '',
            }))
          : [emptyBackupPath(0)]
        // schedule 字段：用于存 HH:MM（sync_mode=scheduled 时）。老数据可能是 cron，兼容取 HH:MM
        let hm = '03:00'
        if (typeof props.task.schedule === 'string') {
          const m = props.task.schedule.match(/^(\d{1,2}):(\d{2})/)
          if (m) hm = `${m[1].padStart(2, '0')}:${m[2]}`
          else {
            // 老 cron 格式 "min hour * * *"，尝试解析
            const c = props.task.schedule.split(/\s+/)
            if (c.length >= 2 && /^\d+$/.test(c[0]) && /^\d+$/.test(c[1])) {
              hm = `${c[1].padStart(2, '0')}:${c[0].padStart(2, '0')}`
            }
          }
        }
        Object.assign(form, {
          server_id: props.task.server_id,
          name: props.task.name || '',
          backup_paths: paths,
          retention_days: props.task.retention_days || 30,
          status: props.task.status || 'enabled',
          remark: props.task.remark || '',
          storage_ids: props.task.storage_ids || [],
          sync_mode: props.task.sync_mode || 'auto',
          schedule_time: hm,
          backup_source: props.task.backup_source || 'external',
          backup_mode: props.task.backup_mode || 'full',
          incremental_storage_id: props.task.incremental_storage_id || null,
          restore_mode: props.task.restore_mode || 'lite',
        })
      }
      loadServers()
      loadStorages()
    }
  },
)

const close = () => emit('update:modelValue', false)

const onSubmit = () => {
  formRef.value?.validate(async (valid) => {
    if (!valid) return

    // 过滤空 path 的行，同时至少留一条；目录打包/快照方式 pattern 整目录处理、强制置空
    const isSimple = backupModeKey.value === 'simple'
    const cleanedPaths = form.backup_paths
      .map((p, i) => ({
        label: (p.label || '').trim() || `备份目录${i + 1}`,
        path: (p.path || '').trim(),
        pattern: isSimple ? (p.pattern || '').trim() : '',
      }))
      .filter((p) => p.path !== '')

    // 三种方式都至少需要一个目录：归档收集要有扫描目录，目录打包/快照要有打包对象
    if (cleanedPaths.length === 0) {
      ElMessage.warning('请至少填写一个备份目录')
      return
    }

    // 目录快照必须选定目标存储（后端同规则 422，前端先拦一道）
    if (form.backup_mode === 'incremental' && !form.incremental_storage_id) {
      ElMessage.warning('请选择快照存储')
      return
    }

    saving.value = true
    try {
      const payload = {
        server_id: form.server_id,
        name: form.name,
        retention_days: form.retention_days,
        status: form.status,
        remark: form.remark,
        storage_ids: form.storage_ids,
        sync_mode: form.sync_mode,
        // schedule 存 HH:MM，仅在 scheduled 模式下有意义
        schedule: form.sync_mode === 'scheduled' ? form.schedule_time : null,
        backup_source: form.backup_source,
        // 备份方式：非 incremental 时不下发目标存储（后端对 full 忽略该字段）
        backup_mode: form.backup_mode,
        incremental_storage_id: form.backup_mode === 'incremental' ? form.incremental_storage_id : null,
        restore_mode: form.restore_mode,
        // 三种方式都提交目录清单（目录打包/快照的 pattern 恒为空）
        backup_paths: cleanedPaths,
      }
      const saved = isEdit.value
        ? await tasksApi.update(props.task.id, payload)
        : await tasksApi.create(payload)
      ElMessage.success(isEdit.value ? '已更新' : '已添加')
      emit('saved', saved)
      close()
    } catch (_) {
      // 拦截器处理错误
    } finally {
      saving.value = false
    }
  })
}
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    :title="title"
    width="1000px"
    :close-on-click-modal="false"
    @update:model-value="(v) => emit('update:modelValue', v)"
  >
    <el-form
      ref="formRef"
      class="wf-form"
      :model="form"
      :rules="rules"
      label-width="88px"
      label-position="right"
      require-asterisk-position="left"
    >
      <div class="wf-grid">
        <!-- 左列：基本信息 -->
        <div class="wf-col wf-col-left">
          <div class="wf-col-title">基本信息</div>

          <el-form-item label="所属服务器" prop="server_id">
            <el-select
              v-model="form.server_id"
              placeholder="选择一台服务器"
              :loading="loadingServers"
              filterable
              style="width: 100%"
            >
              <el-option
                v-for="s in serverOptions"
                :key="s.id"
                :value="s.id"
                :label="`${s.name}${s.ip ? ' (' + s.ip + ')' : ''}`"
              >
                <div class="server-opt">
                  <span>{{ s.name }}</span>
                  <span class="server-opt-meta">
                    {{ s.connect_type === 'agent' ? 'Agent' : 'SSH' }}
                    <span v-if="s.status === 'online'" class="dot-ok" title="在线" />
                    <span v-else-if="s.status === 'offline'" class="dot-gray" title="离线" />
                    <span v-else class="dot-warn" title="待连接" />
                  </span>
                </div>
              </el-option>
            </el-select>
          </el-form-item>

          <el-form-item label="任务名称" prop="name">
            <el-input v-model="form.name" placeholder="例如：应用数据目录" />
          </el-form-item>

          <el-form-item label="推送到" prop="storage_ids">
            <div style="width: 100%">
              <div v-if="mandatoryStorages.length" class="mandatory-notice">
                <span class="mn-title">🔒 强制备份到</span>
                <span
                  v-for="s in mandatoryStorages"
                  :key="s.id"
                  class="mn-chip"
                >{{ s.name }} <span class="mn-type">· {{ s.type }}</span></span>
              </div>

              <el-select
                v-model="form.storage_ids"
                multiple
                filterable
                collapse-tags
                collapse-tags-tooltip
                :placeholder="mandatoryStorages.length ? '还可选其他存储目标（可留空）' : '选择一个或多个存储目标'"
                style="width: 100%"
              >
                <el-option
                  v-for="s in optionalStorages"
                  :key="s.id"
                  :value="s.id"
                  :label="s.name"
                >
                  <div style="display: flex; justify-content: space-between; gap: 10px">
                    <span>{{ s.name }}</span>
                    <span style="font-size: 12px; color: var(--bc-text-secondary)">{{ s.type }}</span>
                  </div>
                </el-option>
              </el-select>

            </div>
          </el-form-item>

          <el-form-item label="保留天数" prop="retention_days">
            <div class="wf-stack">
              <el-input-number v-model="form.retention_days" :min="1" :max="3650" style="width: 160px" />
              <span class="mp-hint">超过天数的备份会被清理</span>
            </div>
          </el-form-item>

          <el-form-item label="恢复模式" prop="restore_mode">
            <el-radio-group v-model="form.restore_mode">
              <el-radio value="lite">留档恢复</el-radio>
              <el-radio value="advanced">自动恢复</el-radio>
            </el-radio-group>
            <div class="wf-stack" style="margin-top: 6px">
              <span class="mp-hint">
                留档恢复：备份文件仅下载回服务器原目录，由管理员手动解压处理。
              </span>
              <span class="mp-hint">
                自动恢复：将解压覆盖到备份时记录的源目录；仅对 Agent 打包 / 快照产物生效，归档收集的外部归档请用留档恢复。
              </span>
            </div>
          </el-form-item>

          <el-form-item label="状态" prop="status">
            <el-radio-group v-model="form.status">
              <el-radio value="enabled">启用</el-radio>
              <el-radio value="disabled">停用</el-radio>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="备注" prop="remark">
            <el-input v-model="form.remark" type="textarea" :rows="2" placeholder="选填" maxlength="500" show-word-limit />
          </el-form-item>
        </div>

        <!-- 右列：备份方式（三选一）+ 该方式的配置 -->
        <div class="wf-col wf-col-right">
          <div class="wf-col-title">备份方式</div>

          <div class="mode-cards">
            <button
              v-for="mc in modeCards"
              :key="mc.key"
              type="button"
              class="mode-card"
              :class="{ active: backupModeKey === mc.key }"
              @click="selectMode(mc.key)"
            >
              <span class="mc-head">
                <span class="mc-icon"><el-icon><component :is="mc.icon" /></el-icon></span>
                <span class="mc-name">{{ mc.name }}</span>
                <span class="mc-check"><el-icon><Check /></el-icon></span>
              </span>
              <span class="mc-method">{{ mc.method }}</span>
              <span class="mc-desc">{{ mc.desc }}</span>
            </button>
          </div>

          <div class="mode-panel">
            <!-- 归档收集：扫描目录里的现成归档文件 -->
            <template v-if="backupModeKey === 'simple'">
              <div class="mp-block">
                <div class="mp-label">备份目录 <em class="mp-req">*</em></div>
                <BackupPathsEditor v-model="form.backup_paths" :server-id="form.server_id" :files-disabled="true" />
                <p class="mp-hint">Agent 扫描这些目录，发现新的归档文件即上传到存储。</p>
              </div>

              <div class="mp-block">
                <div class="mp-label">同步方式</div>
                <div class="sync-mode-row">
                  <el-radio-group v-model="form.sync_mode">
                    <el-radio value="auto">自动检测</el-radio>
                    <el-radio value="scheduled">
                      每天定时
                      <el-time-select
                        v-if="form.sync_mode === 'scheduled'"
                        v-model="form.schedule_time"
                        start="00:00"
                        step="00:10"
                        end="23:50"
                        placeholder="HH:MM"
                        style="width: 130px; margin-left: 8px; vertical-align: middle"
                        @click.stop
                      />
                    </el-radio>
                  </el-radio-group>
                </div>
                <p class="mp-hint">
                  <template v-if="form.sync_mode === 'auto'">
                    Agent 每 <b>2 分钟</b>扫描一次备份目录，一发现新文件立刻上传。
                  </template>
                  <template v-else>
                    Agent 平时<b>不主动扫描</b>；每天到点由 Center 派发一次 sync_now。
                  </template>
                </p>
              </div>
            </template>

            <!-- 目录打包：Agent 对每个目录 tar.gz 打包（self + full）；v1.1.2 起支持勾选单文件（包内保留父目录层） -->
            <template v-if="backupModeKey === 'advanced'">
              <div class="mp-block">
                <div class="mp-label">备份目录 <em class="mp-req">*</em></div>
                <BackupPathsEditor v-model="form.backup_paths" :show-pattern="false" :server-id="form.server_id" :files-disabled="false" />
                <p class="mp-hint">Agent 会对每个目录单独打包为 tar.gz 后上传；勾选文件则单文件打包（保留原目录层级）；可在任务列表点「立即打包」手动触发。</p>
              </div>

              <div class="mp-block">
                <div class="mp-label">打包计划</div>
                <div class="sync-mode-row">
                  <el-radio-group v-model="form.sync_mode">
                    <el-radio value="auto">手动执行</el-radio>
                    <el-radio value="scheduled">
                      每天定时
                      <el-time-select
                        v-if="form.sync_mode === 'scheduled'"
                        v-model="form.schedule_time"
                        start="00:00"
                        step="00:10"
                        end="23:50"
                        placeholder="HH:MM"
                        style="width: 130px; margin-left: 8px; vertical-align: middle"
                        @click.stop
                      />
                    </el-radio>
                  </el-radio-group>
                </div>
                <p class="mp-hint">
                  <template v-if="form.sync_mode === 'auto'">
                    平时不上传任何文件；每次打包由「立即打包」按钮或定时指令触发。
                  </template>
                  <template v-else>
                    每天到点由 Center 派发一次打包指令，Agent 打包全部目录并上传。
                  </template>
                </p>
              </div>
            </template>

            <!-- 目录快照：rsync 硬链快照（self + incremental） -->
            <template v-if="backupModeKey === 'incremental'">
              <div class="mp-block">
                <div class="mp-label">备份目录 <em class="mp-req">*</em></div>
                <BackupPathsEditor v-model="form.backup_paths" :show-pattern="false" :server-id="form.server_id" :files-disabled="true" />
                <p class="mp-hint">rsync 将以这些目录为源做硬链快照；可在任务列表点「立即快照」手动触发。</p>
              </div>

              <div class="mp-block">
                <div class="mp-label">快照存储 <em class="mp-req">*</em></div>
                <el-select
                  v-model="form.incremental_storage_id"
                  placeholder="选择快照目标存储（VPS）"
                  filterable
                  style="width: 100%"
                >
                  <el-option
                    v-for="s in incrementalStorageOptions"
                    :key="s.id"
                    :value="s.id"
                    :label="s.name"
                  >
                    <div style="display: flex; justify-content: space-between; gap: 10px">
                      <span>{{ s.name }}</span>
                      <span style="font-size: 12px; color: var(--bc-text-secondary)">{{ s.type }}</span>
                    </div>
                  </el-option>
                </el-select>
                <p v-if="incrementalStorageOptions.length === 0" class="mp-hint mp-hint-warn">
                  请先创建 SFTP 类型、配置 SSH 私钥、未开启加密的存储（VPS）
                </p>
                <p class="mp-hint">
                  需要目标存储为 SFTP + SSH 私钥且未加密，服务器已安装 rsync / ssh。
                </p>
              </div>
            </template>
          </div>
        </div>
      </div>
    </el-form>

    <template #footer>
      <el-button @click="close">取消</el-button>
      <el-button type="primary" :loading="saving" @click="onSubmit">
        {{ isEdit ? '保存' : '添加' }}
      </el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
/* ---------- 横版两列布局 ---------- */
/* 解除 style.css 全局 .el-dialog .el-form { max-width:520px }（为 460-620px 窄对话框设计）：
   横版双区需要表单满宽，否则 grid 被压成 520px，右列仅剩 ~160px，三张方式卡会被挤成细条 */
.el-dialog .wf-form.wf-form { max-width: none; margin: 0; }
.wf-grid {
  display: grid;
  grid-template-columns: 360px minmax(0, 1fr);
  gap: 20px;
  align-items: start;
}
/* 窄视口兜底：dialog 被全局 max-width:calc(100vw-48px) 压窄后，双列会重新出现右列挤压，
   低于断点改为单列堆叠（方式卡三张仍横排，只是移到基本信息下方） */
@media (max-width: 890px) {
  .wf-grid { grid-template-columns: minmax(0, 1fr); }
}
.wf-col { min-width: 0; }
.wf-col-title {
  font-size: 13px;
  font-weight: 600;
  color: var(--bc-text-primary);
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.wf-col-title::before {
  content: '';
  width: 3px;
  height: 14px;
  border-radius: 2px;
  background: var(--bc-primary);
  flex-shrink: 0;
}
.wf-stack {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.wf-stack .mp-hint { line-height: 1.6; }

.server-opt {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.server-opt-meta {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--bc-text-secondary);
}
.dot-ok, .dot-gray, .dot-warn {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
}
.dot-ok   { background: var(--bc-success); }
.dot-gray { background: var(--bc-text-secondary); }
.dot-warn { background: var(--bc-warning); }

/* ---------- 三种备份方式卡片 ---------- */
.mode-cards {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}
.mode-card {
  position: relative;
  text-align: left;
  border: 1px solid var(--bc-border);
  border-radius: 12px;
  background: var(--bc-bg-card);
  padding: 14px 14px 12px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 3px;
  transition: border-color .15s, background .15s, box-shadow .15s;
  font-family: inherit;
}
.mode-card:hover {
  border-color: var(--bc-primary-200);
  background: var(--bc-primary-light);
}
.mode-card.active {
  border-color: var(--bc-primary);
  background: var(--bc-primary-light);
  box-shadow: 0 0 0 1px var(--bc-primary);
}
.mc-check {
  flex-shrink: 0;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 1px solid var(--bc-border);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: transparent;
  font-size: 12px;
  transition: all .15s;
}
.mode-card.active .mc-check {
  background: var(--bc-primary);
  border-color: var(--bc-primary);
  color: #fff;
}
.mc-head {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
}
.mc-icon {
  color: var(--bc-text-secondary);
  font-size: 16px;
  line-height: 1;
  display: inline-flex;
  flex-shrink: 0;
}
.mode-card.active .mc-icon { color: var(--bc-primary); }
.mc-name {
  flex: 1;
  min-width: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--bc-text-primary);
}
.mc-method {
  font-size: 11px;
  font-weight: 500;
  color: var(--bc-primary);
}
.mc-desc {
  font-size: 11px;
  color: var(--bc-text-secondary);
  line-height: 1.5;
  margin-top: 2px;
}

/* ---------- 方式配置面板 ---------- */
.mode-panel {
  margin-top: 14px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.mp-block { width: 100%; }
.mp-label {
  font-size: 13px;
  font-weight: 500;
  color: var(--bc-text-primary);
  margin-bottom: 8px;
}
.mp-req {
  font-style: normal;
  color: var(--bc-danger);
  margin-left: 2px;
}
.mp-opt {
  font-style: normal;
  font-size: 11px;
  font-weight: 400;
  color: var(--bc-text-secondary);
  margin-left: 6px;
}
.mp-hint {
  font-size: 12px;
  color: var(--bc-text-secondary);
  line-height: 1.6;
  margin: 6px 0 0;
}
.mp-hint code { font-family: ui-monospace, Consolas, monospace; font-size: 12px; }
.mp-hint-warn { color: var(--bc-warning); }

.sync-mode-row { width: 100%; }

/* 强制备份提示区：标题与存储标签同一行（窄视口允许换行） */
.mandatory-notice {
  background: var(--bc-danger-bg);
  border: 1px solid var(--bc-danger-border);
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.mn-title {
  font-size: 12px;
  color: var(--bc-danger);
  font-weight: 500;
  white-space: nowrap;
}
.mn-chip {
  display: inline-flex;
  align-items: center;
  padding: 1px 12px;
  border-radius: 999px;
  background: var(--bc-bg-card);
  border: 1px solid var(--bc-danger-border);
  font-size: 11px;
  color: var(--bc-danger);
  line-height: 1.6;
  letter-spacing: 0.2px;
  white-space: nowrap;
}
.mn-type {
  color: var(--bc-text-secondary);
  margin-left: 4px;
}
</style>
