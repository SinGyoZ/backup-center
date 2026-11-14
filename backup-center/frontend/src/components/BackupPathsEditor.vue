<script setup>
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Close, FolderOpened } from '@element-plus/icons-vue'
import DirBrowserDialog from './DirBrowserDialog.vue'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  // 是否显示文件名通配（pattern）输入：归档收集需要按文件名筛选，目录打包/快照整目录处理无需该字段
  showPattern: { type: Boolean, default: true },
  // 所属服务器 ID：浏览弹窗下发 browse_dir 指令的目标（v1.1.2）
  serverId: { type: [Number, String], default: null },
  // 禁止勾选文件：归档收集/快照模式要求路径必须是目录（v1.1.2）
  filesDisabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const browserVisible = ref(false)

const emptyBackupPath = (idx = 0) => ({
  label: `备份目录${idx + 1}`,
  path: '',
  pattern: '',
})

const onField = (idx, field, value) => {
  emit('update:modelValue', props.modelValue.map((p, i) => (i === idx ? { ...p, [field]: value } : p)))
}

const add = () => {
  emit('update:modelValue', [...props.modelValue, emptyBackupPath(props.modelValue.length)])
}

const remove = (idx) => {
  const next = props.modelValue.filter((_, i) => i !== idx)
  emit('update:modelValue', next.length > 0 ? next : [emptyBackupPath(0)])
}

const openBrowser = () => {
  if (! props.serverId) {
    ElMessage.warning('请先选择所属服务器，再浏览备份目录')
    return
  }
  browserVisible.value = true
}

// 浏览弹窗确认：把勾选的路径合并进列表（按 path 去重；文件路径也允许——
// 目录打包模式 Agent 会按实际路径类型分流打包，见 main.go performPackBackup）
const onBrowserConfirm = (paths) => {
  // 过滤掉从未填写过的空占位行
  const filled = props.modelValue.filter((p) => (p.path || '').trim() !== '')
  const existing = new Set(filled.map((p) => p.path.trim()))
  let added = 0
  paths.forEach((p) => {
    if (existing.has(p)) return
    existing.add(p)
    // label 取末段（目录/文件名），与手动输入的「备份目录N」风格兼容
    const base = p.split('/').filter(Boolean).pop() || p
    filled.push({ label: base, path: p, pattern: '' })
    added += 1
  })
  emit('update:modelValue', filled.length > 0 ? filled : [emptyBackupPath(0)])
  if (added > 0) {
    ElMessage.success(`已添加 ${added} 个勾选项`)
  } else {
    ElMessage.info('勾选的路径都已在列表中')
  }
}
</script>

<template>
  <div class="bp-list">
    <div v-for="(bp, idx) in modelValue" :key="idx" class="bp-card">
      <div class="bp-card-head">
        <el-input
          :model-value="bp.label"
          :placeholder="`备份目录${idx + 1}`"
          class="bp-label-input"
          maxlength="64"
          @update:model-value="(v) => onField(idx, 'label', v)"
        />
        <button
          v-if="modelValue.length > 1"
          type="button"
          class="bp-remove"
          title="移除此目录"
          @click="remove(idx)"
        >
          <el-icon><Close /></el-icon>
        </button>
      </div>
      <el-input
        :model-value="bp.path"
        placeholder="目录或文件的绝对路径，例如 /data/app/uploads"
        class="bp-path-input"
        @update:model-value="(v) => onField(idx, 'path', v)"
      >
        <template #append>
          <button
            type="button"
            class="bp-browse"
            title="从服务器浏览并勾选目录/文件"
            @click="openBrowser"
          >
            <el-icon><FolderOpened /></el-icon>
            <span>浏览</span>
          </button>
        </template>
      </el-input>
      <el-input
        v-if="showPattern"
        :model-value="bp.pattern"
        placeholder="可选，文件名通配，例如 *.tar.gz（留空匹配全部）"
        class="bp-pattern-input"
        @update:model-value="(v) => onField(idx, 'pattern', v)"
      />
    </div>

    <button
      type="button"
      class="bp-add"
      title="添加备份目录"
      @click="add"
    >
      <el-icon><Plus /></el-icon>
      <span>添加备份目录</span>
    </button>

    <DirBrowserDialog
      v-model="browserVisible"
      :server-id="serverId"
      :files-disabled="filesDisabled"
      @confirm="onBrowserConfirm"
    />
  </div>
</template>

<style scoped>
.bp-list {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.bp-card {
  position: relative;
  border: 1px solid var(--bc-border);
  border-radius: 10px;
  padding: 10px 12px 12px;
  background: var(--bc-bg-inset);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.bp-card-head {
  display: flex;
  align-items: center;
  gap: 8px;
}
.bp-label-input :deep(.el-input__wrapper) {
  background: transparent;
  box-shadow: none;
  padding-left: 0;
}
.bp-label-input :deep(.el-input__inner) {
  font-weight: 600;
  color: var(--bc-text-primary);
  font-size: 13px;
}
.bp-remove {
  flex-shrink: 0;
  width: 26px;
  height: 26px;
  border-radius: 6px;
  border: 1px solid var(--bc-border);
  background: var(--bc-bg-card);
  color: var(--bc-text-secondary);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all .15s;
}
.bp-remove:hover {
  color: var(--bc-danger);
  border-color: var(--bc-danger-border);
  background: var(--bc-danger-bg);
}

/* 浏览按钮（v1.1.2）：嵌在路径输入框 append 槽 */
.bp-browse {
  border: none;
  background: transparent;
  color: var(--bc-primary);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 0 4px;
}
.bp-browse:hover { text-decoration: underline; }
.bp-browse .el-icon { font-size: 14px; }

.bp-add {
  width: 100%;
  border: 1px dashed var(--bc-primary-200);
  border-radius: 10px;
  background: var(--bc-primary-light);
  color: var(--bc-primary);
  padding: 10px 12px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: all .15s;
}
.bp-add:hover {
  background: var(--bc-primary-100);
  border-color: var(--bc-primary);
}
.bp-add .el-icon {
  font-size: 14px;
}
</style>
