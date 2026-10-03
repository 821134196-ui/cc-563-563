export const ORDER_STATUS = {
  intake: { label: '收件中', cls: 'blue' },
  paused: { label: '已暂停', cls: 'red' },
  in_care: { label: '洗护中', cls: 'amber' },
  completed: { label: '已完工', cls: 'green' },
  closed: { label: '已关闭', cls: 'gray' },
}

export const GARMENT_STATUS = {
  recorded: { label: '预检中', cls: 'gray' },
  confirmed: { label: '待洗护', cls: 'blue' },
  washing: { label: '洗护中', cls: 'amber' },
  washed: { label: '待核对', cls: 'purple' },
  disputed: { label: '争议中', cls: 'red' },
  done: { label: '已完结', cls: 'green' },
}

export const DISPUTE_STATUS = {
  open: { label: '待复检', cls: 'red' },
  rechecking: { label: '复检中', cls: 'amber' },
  negotiating: { label: '赔偿协商中', cls: 'purple' },
  approved: { label: '赔付已批准', cls: 'blue' },
  rejected: { label: '赔付已驳回', cls: 'gray' },
  paid: { label: '已赔付', cls: 'green' },
  closed: { label: '已关闭', cls: 'gray' },
}

export const COMP_STATUS = {
  pending: { label: '待审批', cls: 'amber' },
  approved: { label: '已批准', cls: 'blue' },
  rejected: { label: '已驳回', cls: 'gray' },
  paid: { label: '已付款', cls: 'green' },
}

export const CARE_LABELS = {
  no_water_wash: '🚫 禁止水洗',
  no_dry_clean: '🚫 不可干洗',
  no_bleach: '🚫 不可漂白',
  hand_wash_only: '🖐 仅可手洗',
  low_temp_iron: '低温熨烫',
  shade_dry: '阴干',
}

export const PAUSE_REASONS = {
  customer_refused_sign: '顾客拒签',
  label_forbids_water_wash: '护理标签禁止水洗',
  pocket_items_found: '发现口袋物品',
}

export function Badge({ map, value }) {
  const cfg = map[value] || { label: value, cls: 'gray' }
  return <span className={`badge ${cfg.cls}`}>{cfg.label}</span>
}

export function yuan(cents) {
  return `¥${(Number(cents || 0) / 100).toFixed(2)}`
}

export function fmtTime(t) {
  if (!t) return '—'
  const d = new Date(t)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

export const ACTION_LABELS = {
  'order.created': '创建订单',
  'order.paused': '订单暂停',
  'order.resumed': '订单恢复',
  'order.confirmed_by_customer': '顾客确认、证据锁定',
  'garment.created': '衣物登记入库',
  'garment.washing_started': '开始洗护',
  'garment.washing_finished': '洗护完成',
  'garment.checked_consistent': '完工核对一致',
  'dispute.opened': '发现差异、开启争议',
  'dispute.recheck_confirmed_discrepancy': '复检确认差异仍存在，转入赔偿协商',
  'dispute.recheck_requested': '发起复检',
  'dispute.note_added': '添加协商记录',
  'compensation.requested': '发起赔付申请',
  'compensation.approved': '店长批准赔付',
  'compensation.rejected': '店长驳回赔付',
  'compensation.paid': '赔付付款完成',
}
