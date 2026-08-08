// ESC/POS receipt printer driver via WebUSB (Chrome/Edge only).
// Supports the common 80mm thermal printers (Epson TM-T20, Star TSP, Bixolon, etc.)
export class EscPos {
  static ESC = 0x1b;
  static GS = 0x1d;
  static LF = 0x0a;

  constructor() { this.device = null; }

  async pair() {
    // Most thermal printers expose vendor 0x04b8 (Epson), 0x0519 (Star), 0x1504 (Bixolon).
    this.device = await navigator.usb.requestDevice({ filters: [
      { vendorId: 0x04b8 }, { vendorId: 0x0519 }, { vendorId: 0x1504 }, { vendorId: 0x0dd4 },
    ]});
    await this.device.open();
    if (this.device.configuration === null) await this.device.selectConfiguration(1);
    const iface = this.device.configuration.interfaces[0];
    this.endpoint = iface.alternate.endpoints.find(e => e.direction === 'out').endpointNumber;
    await this.device.claimInterface(iface.interfaceNumber);
  }

  async write(buf) {
    if (!this.device) throw new Error('Printer not paired');
    await this.device.transferOut(this.endpoint, buf);
  }

  async printReceipt(receipt) {
    const enc = new TextEncoder();
    const bytes = [];
    bytes.push(EscPos.ESC, 0x40); // init
    bytes.push(EscPos.ESC, 0x61, 0x01); // center
    bytes.push(EscPos.ESC, 0x21, 0x10); // double height
    enc.encode(receipt.store_name + '\n').forEach(b => bytes.push(b));
    bytes.push(EscPos.ESC, 0x21, 0x00); // normal
    enc.encode((receipt.address || '') + '\n').forEach(b => bytes.push(b));
    bytes.push(EscPos.ESC, 0x61, 0x00); // left
    enc.encode('\n').forEach(b => bytes.push(b));
    (receipt.items || []).forEach(i => {
      const line = `${i.quantity}x ${i.name}`.padEnd(30) + ('$' + i.line_total.toFixed(2)).padStart(10);
      enc.encode(line + '\n').forEach(b => bytes.push(b));
    });
    enc.encode('-'.repeat(40) + '\n').forEach(b => bytes.push(b));
    enc.encode(`Subtotal`.padEnd(30) + ('$' + receipt.subtotal.toFixed(2)).padStart(10) + '\n').forEach(b => bytes.push(b));
    enc.encode(`Tax`.padEnd(30) + ('$' + receipt.tax.toFixed(2)).padStart(10) + '\n').forEach(b => bytes.push(b));
    enc.encode(`TOTAL`.padEnd(30) + ('$' + receipt.grand.toFixed(2)).padStart(10) + '\n\n\n').forEach(b => bytes.push(b));
    bytes.push(EscPos.GS, 0x56, 0x42, 0x00); // cut
    await this.write(new Uint8Array(bytes));
  }

  async kickDrawer() {
    // Pulse pin 2, 50ms on / 250ms off
    await this.write(new Uint8Array([EscPos.ESC, 0x70, 0x00, 0x32, 0xfa]));
  }
}
