// Finova · Orders — NGUỒN DỮ LIỆU MẪU DUY NHẤT. Muốn đổi số liệu/danh sách, chỉ sửa file này (hoặc thay hàm loadOrders bằng lời gọi API thật).

export const PAGE_SIZE = 8;                 // số đơn mỗi trang (ảnh tham chiếu: trang cuối = 979 khi tổng 7.830)
export const SAMPLE_TODAY = '2024-10-24';   // "hôm nay" của dữ liệu mẫu, làm mốc cho bộ lọc Date

export const STATUSES = ['Delivered', 'Shipped', 'Pending', 'Returned', 'Cancelled'];
export const PAYMENTS = ['Credit Card', 'Cash on Delivery', 'PayPal', 'Bank Transfer'];

export const DATE_FILTERS = [
  { value: '', label: 'Date' },
  { value: '7d', label: 'Last 7 days', days: 7 },
  { value: '30d', label: 'Last 30 days', days: 30 },
  { value: '90d', label: 'Last 90 days', days: 90 },
  { value: 'year', label: 'This year', year: 2024 },
];

export const SORTS = [
  { value: 'newest', label: 'Sort' },
  { value: 'oldest', label: 'Oldest' },
  { value: 'amount-desc', label: 'Amount ↓' },
  { value: 'amount-asc', label: 'Amount ↑' },
  { value: 'customer-asc', label: 'Customer A–Z' },
];

export const CURRENT_USER = { name: 'Guy Hawkins', role: 'Admin' };

/** Bốn thẻ thống kê (số liệu mẫu). `status` = trạng thái đơn tương ứng, dùng để cập nhật số khi người dùng sửa/xoá/tạo đơn. */
export const SUMMARY = [
  { key: 'total', label: 'Total Orders', value: 7830, change: 12, icon: 'clipboard', tone: 'brand', status: null },
  { key: 'pending', label: 'Pending', value: 342, change: 5, icon: 'clock', tone: 'orange', status: 'Pending' },
  { key: 'delivered', label: 'Delivered', value: 6910, change: 18, icon: 'package-check', tone: 'green', status: 'Delivered' },
  { key: 'returned', label: 'Returned', value: 578, change: -13, icon: 'undo', tone: 'red', status: 'Returned' },
];

export const NOTIFICATIONS = [
  { title: 'New order #ORD-00183', text: 'Jane Cooper placed an order of $420.', time: '2 min ago' },
  { title: 'Payment received', text: 'PayPal payment for #ORD-00180 cleared.', time: '1 hour ago' },
  { title: 'Return requested', text: 'Ralph Edwards requested a return for #ORD-00179.', time: 'Yesterday' },
];

const CUSTOMERS = [
  'Cody Fisher', 'Eleanor Pena', 'Darlene Robertson', 'Ralph Edwards', 'Jane Cooper', 'Wade Warren', 'Esther Howard', 'Cameron Williamson',
  'Brooklyn Simmons', 'Leslie Alexander', 'Jenny Wilson', 'Robert Fox', 'Jacob Jones', 'Kristin Watson', 'Devon Lane', 'Courtney Henry',
  'Kathryn Murphy', 'Savannah Nguyen', 'Dianne Russell', 'Albert Flores', 'Marvin McKinney', 'Floyd Miles', 'Annette Black', 'Theresa Webb',
];

// Bốn đơn đầu tiên đúng như đặc tả; các đơn còn lại sinh xác định (cùng một kết quả ở mọi lần tải).
const SEED = [
  { n: 182, customer: 'Cody Fisher', items: 3, amount: 1240, payment: 'Credit Card', date: '2024-10-24', status: 'Delivered' },
  { n: 181, customer: 'Eleanor Pena', items: 1, amount: 340, payment: 'Cash on Delivery', date: '2024-10-23', status: 'Shipped' },
  { n: 180, customer: 'Darlene Robertson', items: 5, amount: 2070, payment: 'PayPal', date: '2024-10-22', status: 'Pending' },
  { n: 179, customer: 'Ralph Edwards', items: 2, amount: 590, payment: 'Credit Card', date: '2024-10-21', status: 'Returned' },
];

export const STATUS_WEIGHTS = [['Delivered', 0.8], ['Shipped', 0.06], ['Pending', 0.05], ['Returned', 0.07], ['Cancelled', 0.02]];

function rng(seed) {                         // mulberry32
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

export function isoAddDays(iso, days) {
  const d = new Date(iso + 'T00:00:00Z');
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

export const orderId = (n) => '#ORD-' + String(n).padStart(5, '0');

/** Sinh `total` đơn. Số đơn n đi 182…1 rồi 7830…183 nên mọi mã duy nhất và tập hợp đúng 1…total. */
export function generateOrders(total = 7830) {
  const r = rng(20241024);
  const pick = (list) => list[Math.floor(r() * list.length)];
  const out = [];
  for (let i = 0; i < total; i++) {
    if (i < SEED.length) {
      out.push({ ...SEED[i], id: orderId(SEED[i].n) });
      continue;
    }
    const n = i <= 181 ? 182 - i : total - (i - 182);
    const items = 1 + Math.floor(r() * 6);
    const roll = r();
    let acc = 0;
    let status = 'Delivered';
    for (const [s, w] of STATUS_WEIGHTS) {
      acc += w;
      if (roll < acc) { status = s; break; }
    }
    out.push({
      id: orderId(n), n, customer: pick(CUSTOMERS), items,
      amount: Math.max(20, Math.round(items * (45 + r() * 330) / 10) * 10),
      payment: pick(PAYMENTS), status,
      date: isoAddDays('2024-10-21', -Math.floor((i - 3) / 21)),
    });
  }
  return out;
}

/**
 * Tải dữ liệu đơn hàng. Hiện là dữ liệu mẫu nên trả về sau một độ trễ ngắn để thấy trạng thái loading;
 * khi nối API thật chỉ cần thay thân hàm này. Thêm `?demo=error` vào URL để xem trạng thái lỗi.
 */
export function loadOrders() {
  return new Promise((resolve, reject) => {
    setTimeout(() => {
      if (new URLSearchParams(location.search).get('demo') === 'error') {
        reject(new Error('Could not load orders. Please try again.'));
        return;
      }
      try { resolve(generateOrders()); } catch (e) { reject(e); }
    }, 450);
  });
}
