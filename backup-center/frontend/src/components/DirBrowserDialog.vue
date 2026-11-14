<script setup>
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { ArrowLeft, Refresh, Folder, Document, Position } from '@element-plus/icons-vue'
import { serversApi } from '@/api/servers'
import { commandsApi } from '@/api/commands'

/**
 * 备份目录浏览弹窗（v1.1.2 / Agent v0.17.0）：
 * 从服务器根目录逐级浏览（browse_dir 指令 → 3 秒快速拉取），勾选目录/文件（多选、跨层保持），
 * 确认后把勾选路径交给父组件写入 backup_paths。
 * filesDisabled=true（归档收集/快照模式）时文件行不可勾选。
 */
const props = defineProps({
  modelValue: { type: Boolean, default: false }, // 可见性
  serverId: { type: [Number, String], default: null },
  filesDisabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'confirm'])

const visible = ref(false)
const currentPath = ref('/')
const entries = ref([])
const loading = ref(false)
const error = ref('')
const manualPath = ref('')
// 已勾选的完整路径集合（Set，跨层保持）
const selected = ref(new Set())
const truncated = ref(false)

const selectedList = computed(() => [...selected.value])

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

const humanizeSize = (bytes, isDir) => {
  if (isDir) return '—'
  if (!bytes && bytes !== 0) return ''
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let v = bytes
  let i = 0
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i += 1 }
  return `${v.toFixed(v >= 100 || i === 0 ? 0 : 1)} ${units[i]}`
}

const shortTime = (iso) => {
  if (!iso) return ''
  try {
    const d = new Date(iso)
    const pad = (n) => String(n).padStart(2, '0')
    return `${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
  } catch { return '' }
}

// 面包屑分段：/  >  mnt  >  BBC
const crumbs = computed(() => {
  const segs = currentPath.value === '/' ? [] : currentPath.value.replace(/^\/+/, '').split('/')
  const list = [{ name: '/', path: '/' }]
  let acc = ''
  segs.forEach((s) => {
    acc = `${acc}/${s}`
    list.push({ name: s, path: acc })
  })
  return list
})

const sortedEntries = computed(() => {
  const dirs = entries.value.filter((e) => e.is_dir)
  const files = entries.value.filter((e) => ! e.is_dir)
  const byName = (a, b) => a.name.localeCompare(b.name, 'zh-Hans-CN', { numeric: true })
  return [...dirs.sort(byName), ...files.sort(byName)]
})

const childPath = (name, isDir) => {
  const base = currentPath.value === '/' ? '' : currentPath.value
  return isDir ? `${base}/${name}` : `${base}/${name}`
}

const parentOf = (p) => {
  if (p === '/') return null
  const idx = p.lastIndexOf('/')
  return idx <= 0 ? '/' : p.slice(0, idx)
}

// 下发浏览指令并轮询结果（browse_dir 走 3 秒快速拉取通道，正常 1-2 秒回包）
const loadDir = async (path) => {
  if (! props.serverId) {
    ElMessage.warning('请先选择所属服务器')
    return
  }
  loading.value = true
  error.value = ''
  entries.value = []
  try {
    const { command_id: commandId } = await serversApi.browse(props.serverId, path || '/')
    const started = Date.now()
    for (;;) {
      await sleep(1000)
      const resp = await commandsApi.get(commandId)
      const cmd = resp?.command
      if (cmd?.status === 'success') {
        const payload = JSON.parse(cmd.output || '{}')
        currentPath.value = payload.path || path || '/'
        entries.value = Array.isArray(payload.entries) ? payload.entries : []
        truncated.value = !! payload.truncated
        if (truncated.value) {
          ElMessage.warning(`目录条目超过 1000 项，已截断显示；可进入子目录浏览或直接手输路径`)
        }
        return
      }
      if (cmd?.status === 'failed') {
        throw new Error(cmd.error || '浏览失败')
      }
      if (Date.now() - started > 12000) {
        throw new Error('Agent 12 秒内未响应：请确认服务器在线、Agent 版本 ≥ v0.18.0（旧版 Agent 不支持目录浏览）')
      }
    }
  } catch (e) {
    error.value = e?.message || String(e)
  } finally {
    loading.value = false
  }
}

const toggleSelect = (entry) => {
  if (! entry.is_dir && props.filesDisabled) return
  const p = childPath(entry.name, entry.is_dir)
  if (selected.value.has(p)) {
    selected.value.delete(p)
  } else {
    selected.value.add(p)
  }
  // 触发响应式：Set 引用替换
  selected.value = new Set(selected.value)
}

const goManual = () => {
  const p = manualPath.value.trim()
  if (! p) return
  loadDir(p.startsWith('/') ? p : `/${p}`)
}

const open = () => { emit('update:modelValue', true) }

const close = () => { emit('update:modelValue', false) }

const onConfirm = () => {
  if (selectedList.value.length === 0) {
    ElMessage.warning('尚未勾选任何目录或文件')
    return
  }
  emit('confirm', selectedList.value)
  emit('update:modelValue', false)
}

watch(
  () => props.modelValue,
  (v) => {
    visible.value = v
    if (v) {
      selected.value = new Set()
      manualPath.value = ''
      currentPath.value = '/'
      loadDir('/')
    }
  },
)
watch(visible, (v) => { if (! v) emit('update:modelValue', false) })
</script>

<template>
  <el-dialog
    v-model="visible"
    title="浏览备份目录（勾选要备份的目录 / 文件）"
    width="640px"
    top="8vh"
    append-to-body
    destroy-on-close
  >
    <!-- 面包屑 + 手输直达 -->
    <div class="db-bar">
      <el-button
        :icon="ArrowLeft"
        :disabled="loading || currentPath === '/'"
        size="small"
        title="上一级"
        @click="loadDir(parentOf(currentPath))"
      />
      <el-button
        :icon="Refresh"
        :disabled="loading"
        size="small"
        title="刷新当前目录"
        @click="loadDir(currentPath)"
      />
      <div class="db-crumbs">
        <template v-for="(c, i) in crumbs" :key="c.path">
          <span v-if="i > 0" class="db-crumb-sep">/</span>
          <a class="db-crumb" @click="!loading && loadDir(c.path)">{{ c.name }}</a>
        </template>
      </div>
      <el-input
        v-model="manualPath"
        placeholder="手输路径直达，如 /mnt"
        size="small"
        class="db-manual"
        @keyup.enter="goManual"
      >
        <template #append>
          <el-button :icon="Position" @click="goManual" />
        </template>
      </el-input>
    </div>

    <!-- 目录列表 -->
    <div v-loading="loading" class="db-list">
      <template v-if="!loading && !error">
        <div v-if="sortedEntries.length === 0" class="db-empty">（空目录）</div>
        <div
          v-for="e in sortedEntries"
          :key="e.name"
          class="db-row"
          :class="{ 'is-dir': e.is_dir, 'is-checked': selected.has(childPath(e.name, e.is_dir)), 'is-disabled': !e.is_dir && filesDisabled }"
          @click="e.is_dir ? loadDir(childPath(e.name, true)) : toggleSelect(e)"
        >
          <el-checkbox
            :model-value="selected.has(childPath(e.name, e.is_dir))"
            :disabled="!e.is_dir && filesDisabled"
            @click.stop
            @change="toggleSelect(e)"
          />
          <el-icon class="db-icon" :class="{ 'db-icon-dir': e.is_dir }">
            <Folder v-if="e.is_dir" />
            <Document v-else />
          </el-icon>
          <span class="db-name" :title="e.name">{{ e.name }}</span>
          <span class="db-meta">{{ humanizeSize(e.size, e.is_dir) }}</span>
          <span class="db-meta">{{ shortTime(e.mtime) }}</span>
        </div>
      </template>
      <div v-if="error && !loading" class="db-error">
        <p>{{ error }}</p>
        <el-button size="small" @click="loadDir(currentPath === '/' ? '/' : currentPath)">重试</el-button>
      </div>
    </div>

    <p v-if="filesDisabled" class="db-hint">
      当前备份方式要求目录：只能勾选文件夹，文件不可选。
    </p>

    <template #footer>
      <div class="db-footer">
        <div class="db-selected">
          <template v-if="selectedList.length">
            <span class="db-selected-label">已选 {{ selectedList.length }} 项：</span>
            <el-tag
              v-for="p in selectedList"
              :key="p"
              size="small"
              closable
              class="db-tag"
              @close="selected.delete(p); selected = new Set(selected)"
            >
              {{ p }}
            </el-tag>
          </template>
          <span v-else class="db-selected-label db-selected-empty">勾选目录或文件（可多选，勾文件夹=整目录打包）</span>
        </div>
        <div>
          <el-button @click="close">取消</el-button>
          <el-button type="primary" :disabled="selectedList.length === 0" @click="onConfirm">确定</el-button>
        </div>
      </div>
    </template>
  </el-dialog>
</template>

<style scoped>
.db-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}
.db-crumbs {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: 13px;
}
.db-crumb {
  color: var(--bc-primary);
  cursor: pointer;
  text-decoration: none;
}
.db-crumb:hover { text-decoration: underline; }
.db-crumb-sep { color: var(--bc-text-secondary); margin: 0 2px; }
.db-manual { width: 220px; flex-shrink: 0; }

.db-list {
  border: 1px solid var(--bc-border);
  border-radius: 8px;
  min-height: 280px;
  max-height: 46vh;
  overflow-y: auto;
  background: var(--bc-bg-inset, #fafbfc);
}
.db-empty {
  padding: 40px;
  text-align: center;
  color: var(--bc-text-secondary);
  font-size: 13px;
}
.db-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 12px;
  font-size: 13px;
  border-bottom: 1px solid var(--bc-border, #eef1f5);
  cursor: pointer;
  transition: background .12s;
}
.db-row:hover { background: var(--bc-primary-light, #f0f6ff); }
.db-row.is-checked { background: var(--bc-primary-light, #f0f6ff); }
.db-row.is-disabled { cursor: not-allowed; opacity: .55; }
.db-row.is-disabled:hover { background: transparent; }
.db-icon { color: var(--bc-text-secondary); flex-shrink: 0; }
.db-icon-dir { color: var(--bc-primary, #3b82f6); }
.db-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.db-meta {
  flex-shrink: 0;
  color: var(--bc-text-secondary);
  font-size: 12px;
  width: 64px;
  text-align: right;
}
.db-error { padding: 24px; text-align: center; color: var(--bc-danger, #d9534f); font-size: 13px; }
.db-error .el-button { margin-top: 10px; }

.db-hint {
  margin: 10px 0 0;
  font-size: 12px;
  color: var(--bc-warning-text, #b45309);
}

.db-footer {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.db-selected {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
}
.db-selected-label { font-size: 12px; color: var(--bc-text-secondary); }
.db-selected-empty { font-size: 12px; }
.db-tag { max-width: 240px; }
.db-tag :deep(.el-tag__content) {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  display: inline-block;
  max-width: 200px;
}
</style>
