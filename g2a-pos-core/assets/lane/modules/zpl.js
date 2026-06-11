// ZPL label generator + WebUSB send. Compatible with Zebra ZD220/ZD420/etc.
export class Zpl {
  static ZEBRA_VENDOR = 0x0a5f;

  constructor() { this.device = null; }

  async pair() {
    this.device = await navigator.usb.requestDevice({ filters: [{ vendorId: Zpl.ZEBRA_VENDOR }] });
    await this.device.open();
    if (this.device.configuration === null) await this.device.selectConfiguration(1);
    const iface = this.device.configuration.interfaces[0];
    this.endpoint = iface.alternate.endpoints.find(e => e.direction === 'out').endpointNumber;
    await this.device.claimInterface(iface.interfaceNumber);
  }

  static priceTag({ sku, name, price, barcode }) {
    return [
      '^XA',
      '^PW400',
      '^FO20,20^A0N,28,28^FD' + name.slice(0, 24) + '^FS',
      '^FO20,60^A0N,22,22^FDSKU ' + sku + '^FS',
      '^FO20,90^A0N,36,36^FD$' + price.toFixed(2) + '^FS',
      '^FO20,140^BY2,3,80^BCN,80,Y,N,N^FD' + (barcode || sku) + '^FS',
      '^XZ',
    ].join('\n');
  }

  static firearmTag({ manufacturer, model, caliber, serial }) {
    return [
      '^XA', '^PW400',
      '^FO20,20^A0N,26,26^FD' + (manufacturer + ' ' + model).slice(0, 28) + '^FS',
      '^FO20,55^A0N,22,22^FD' + caliber + '^FS',
      '^FO20,90^BY2,3,80^BCN,80,Y,N,N^FD' + serial + '^FS',
      '^FO20,200^A0N,18,18^FDSN ' + serial + '^FS',
      '^XZ',
    ].join('\n');
  }

  async print(zpl) {
    if (!this.device) throw new Error('Label printer not paired');
    const bytes = new TextEncoder().encode(zpl + '\n');
    await this.device.transferOut(this.endpoint, bytes);
  }
}
