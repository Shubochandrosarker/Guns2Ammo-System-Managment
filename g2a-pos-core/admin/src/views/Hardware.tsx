import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Profile {
  id: number;
  register_code: string;
  location_id: number;
  receipt_printer_driver: string;
  receipt_printer_endpoint: string;
  cash_drawer_via_printer: number;
  barcode_scanner_profile: string;
  customer_display_driver: string;
  id_scanner_driver: string;
}

interface ProfileForm {
  register_code?: string;
  location_id: number;
  receipt_printer_driver: string;
  receipt_printer_endpoint?: string;
  cash_drawer_via_printer: number;
  barcode_scanner_profile: string;
  signature_pad_driver: string;
  customer_display_driver?: string;
  customer_display_endpoint?: string;
  id_scanner_driver: string;
}

export default function Hardware() {
  const [rows, setRows] = useState<Profile[]>([]);
  const [form, setForm] = useState<ProfileForm>({
    location_id: 1,
    receipt_printer_driver: 'escpos_network',
    cash_drawer_via_printer: 1,
    barcode_scanner_profile: 'usb_hid',
    signature_pad_driver: 'web_canvas',
    id_scanner_driver: 'aamva_pdf417_keyboard',
  });

  const refresh = async () => {
    const r = await get<{ items: Profile[] }>('/hardware/profiles');
    setRows(r.items || []);
  };
  useEffect(() => { queueMicrotask(refresh); }, []);

  const save = async () => {
    if (!form.register_code) return;
    await post('/hardware/profiles', form);
    await refresh();
  };

  const kickDrawer = async () => {
    const r = await post<{ bytes_base64: string }>('/hardware/drawer/kick', {});
    alert(`Generated ${r.bytes_base64.length} byte kick code (POST to your printer at the configured endpoint).`);
  };

  const cols: Column<Profile>[] = [
    { key: 'register_code', label: 'Register' },
    { key: 'location_id', label: 'Loc' },
    { key: 'receipt_printer_driver', label: 'Printer' },
    { key: 'receipt_printer_endpoint', label: 'Endpoint' },
    { key: 'cash_drawer_via_printer', label: 'Drawer→Printer', render: (r) => r.cash_drawer_via_printer ? 'yes' : 'no' },
    { key: 'barcode_scanner_profile', label: 'Scanner' },
    { key: 'customer_display_driver', label: 'Cust display' },
    { key: 'id_scanner_driver', label: 'ID scanner' },
  ];

  return (
    <div>
      <PageHeader title="Hardware Profiles" subtitle="Per-register: ESC/POS printer, cash drawer kick, scanner profile, customer display, signature pad, ID scanner."
        actions={<button className="btn" onClick={kickDrawer}>Test drawer kick</button>} />
      <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
        <input className="input" placeholder="Register code (e.g. COUNTER-1)" value={form.register_code ?? ''} onChange={(e) => setForm({...form, register_code: e.target.value})} />
        <input className="input" type="number" placeholder="Location ID" value={form.location_id} onChange={(e) => setForm({...form, location_id: parseInt(e.target.value)})} />
        <select className="input" value={form.receipt_printer_driver} onChange={(e) => setForm({...form, receipt_printer_driver: e.target.value})}>
          <option value="escpos_network">ESC/POS network (TCP:9100)</option>
          <option value="escpos_usb_helper">ESC/POS via desktop helper</option>
          <option value="cups">CUPS</option>
        </select>
        <input className="input" placeholder="Printer endpoint (e.g. 192.168.1.30:9100)"
          value={form.receipt_printer_endpoint ?? ''} onChange={(e) => setForm({...form, receipt_printer_endpoint: e.target.value})} />
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!form.cash_drawer_via_printer}
          onChange={(e) => setForm({...form, cash_drawer_via_printer: e.target.checked ? 1 : 0})} /> Cash drawer via printer</label>
        <select className="input" value={form.barcode_scanner_profile} onChange={(e) => setForm({...form, barcode_scanner_profile: e.target.value})}>
          <option value="usb_hid">USB HID keyboard wedge</option>
          <option value="bluetooth_hid">Bluetooth HID</option>
          <option value="serial">Serial</option>
        </select>
        <select className="input" value={form.customer_display_driver ?? ''} onChange={(e) => setForm({...form, customer_display_driver: e.target.value})}>
          <option value="">(none)</option>
          <option value="web_browser">Web browser tab</option>
          <option value="serial_vfd">Serial VFD pole</option>
          <option value="android_tablet">Android tablet (HTTP push)</option>
        </select>
        <input className="input" placeholder="Cust display endpoint" value={form.customer_display_endpoint ?? ''} onChange={(e) => setForm({...form, customer_display_endpoint: e.target.value})} />
        <select className="input" value={form.id_scanner_driver} onChange={(e) => setForm({...form, id_scanner_driver: e.target.value})}>
          <option value="aamva_pdf417_keyboard">AAMVA PDF417 keyboard wedge</option>
          <option value="serial_drivers_license">Serial driver&apos;s-license scanner</option>
        </select>
        <button className="btn-primary md:col-span-3" onClick={save}>Save profile</button>
      </div>
      <DataTable<Profile> rows={rows} columns={cols} loading={false} rowKey={(r) => r.id} empty="No hardware profiles." />
    </div>
  );
}
