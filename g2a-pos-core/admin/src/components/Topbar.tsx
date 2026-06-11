export default function Topbar({ theme, onToggleTheme, onToggleSidebar }: { theme: 'light' | 'dark'; onToggleTheme: () => void; onToggleSidebar: () => void }) {
  const user = window.G2A_POS_ADMIN?.currentUser;
  return (
    <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-zinc-200 bg-white/90 px-4 backdrop-blur-xl dark:border-zinc-800 dark:bg-zinc-900/90 sm:px-6">
      <div className="flex min-w-0 items-center gap-3">
        <button onClick={onToggleSidebar} aria-label="Toggle sidebar" className="grid h-9 w-9 place-items-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">☰</button>
        <div className="min-w-0"><div className="truncate text-sm font-semibold tracking-tight sm:text-base">Guns 2 Ammo POS</div><div className="hidden text-[10px] uppercase tracking-[.16em] text-zinc-400 sm:block">Secure operations workspace</div></div>
      </div>
      <div className="flex items-center gap-2 sm:gap-3">
        <button type="button" className="hidden items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-500 hover:border-brand/30 hover:text-brand dark:border-zinc-700 dark:bg-zinc-800 lg:inline-flex" onClick={() => window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }))}><span>Ask G2A Agent</span><kbd className="rounded border border-zinc-300 bg-white px-1.5 py-0.5 text-[10px] dark:border-zinc-600 dark:bg-zinc-900">⌘K</kbd></button>
        <button onClick={onToggleTheme} className="grid h-9 w-9 place-items-center rounded-lg border border-zinc-200 bg-white text-sm hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800" aria-label={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'} title={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}>{theme === 'dark' ? '☀️' : '🌙'}</button>
        {user && <div className="flex items-center gap-2 rounded-xl bg-zinc-100 py-1.5 pl-1.5 pr-3 text-sm dark:bg-zinc-800"><span className="grid h-7 w-7 place-items-center rounded-lg bg-brand text-xs font-bold text-white">{String(user.name || 'U').slice(0, 1).toUpperCase()}</span><span className="hidden font-medium sm:inline">{user.name}</span></div>}
      </div>
    </header>
  );
}
