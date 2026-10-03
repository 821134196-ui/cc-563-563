import { Badge } from './ui.jsx';

export const ORDER_STATUS_TONE = {
    intake: 'blue',
    awaiting_confirmation: 'amber',
    paused: 'red',
    in_wash: 'blue',
    ready: 'purple',
    completed: 'green',
    cancelled: 'gray',
};

export const GARMENT_STATUS_TONE = {
    intake: 'blue',
    confirmed: 'gray',
    washing: 'blue',
    ready_inspection: 'amber',
    inspection_passed: 'green',
    disputed: 'red',
    rechecking: 'purple',
    completed: 'green',
};

export function OrderStatusBadge({ status, status_label }) {
    return <Badge tone={ORDER_STATUS_TONE[status] || 'gray'}>{status_label || status}</Badge>;
}

export function GarmentStatusBadge({ status, status_label }) {
    return <Badge tone={GARMENT_STATUS_TONE[status] || 'gray'}>{status_label || status}</Badge>;
}
