import { useEffect, useRef, useState } from 'react';
import { post } from '../api';

interface Props {
  subjectType: string;
  subjectId: number;
  role?: string;
  defaultName?: string;
  onSigned?: (id: number) => void;
}

/**
 * Mouse/touch-driven signature canvas. Sends the rendered PNG as base64 to
 * /signatures. Used by 4473 transferee/transferor, repair pickup, class
 * waiver, etc.
 */
export default function SignaturePad({ subjectType, subjectId, role = 'signer', defaultName = '', onSigned }: Props) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const drawing = useRef(false);
  const [name, setName] = useState(defaultName);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = '#111';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
  }, []);

  const pos = (e: React.PointerEvent<HTMLCanvasElement>) => {
    const rect = (e.target as HTMLCanvasElement).getBoundingClientRect();
    return { x: e.clientX - rect.left, y: e.clientY - rect.top };
  };

  const start = (e: React.PointerEvent<HTMLCanvasElement>) => {
    drawing.current = true;
    const ctx = canvasRef.current?.getContext('2d');
    if (!ctx) return;
    const { x, y } = pos(e);
    ctx.beginPath();
    ctx.moveTo(x, y);
  };
  const move = (e: React.PointerEvent<HTMLCanvasElement>) => {
    if (!drawing.current) return;
    const ctx = canvasRef.current?.getContext('2d');
    if (!ctx) return;
    const { x, y } = pos(e);
    ctx.lineTo(x, y);
    ctx.stroke();
  };
  const stop = () => { drawing.current = false; };

  const clear = () => {
    const canvas = canvasRef.current;
    const ctx = canvas?.getContext('2d');
    if (!canvas || !ctx) return;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
  };

  const submit = async () => {
    const canvas = canvasRef.current;
    if (!canvas || !name) return;
    setBusy(true);
    try {
      const dataUrl = canvas.toDataURL('image/png');
      const res = await post<{ id: number }>('/signatures', {
        subject_type: subjectType,
        subject_id: subjectId,
        role,
        signer_name: name,
        image_data: dataUrl,
      });
      onSigned?.(res.id);
      clear();
    } finally { setBusy(false); }
  };

  return (
    <div className="card p-3">
      <div className="mb-2 flex items-center gap-2">
        <input className="input flex-1" placeholder="Signer name" value={name} onChange={(e) => setName(e.target.value)} />
        <button className="btn-sm" onClick={clear}>Clear</button>
        <button className="btn-primary" onClick={submit} disabled={busy || !name}>{busy ? 'Saving…' : 'Sign'}</button>
      </div>
      <canvas
        ref={canvasRef}
        width={500}
        height={150}
        className="w-full border border-zinc-300 rounded-md touch-none"
        onPointerDown={start}
        onPointerMove={move}
        onPointerUp={stop}
        onPointerLeave={stop}
      />
      <p className="text-xs text-zinc-500 mt-1">By signing you agree your electronic signature is binding (E-SIGN Act).</p>
    </div>
  );
}
