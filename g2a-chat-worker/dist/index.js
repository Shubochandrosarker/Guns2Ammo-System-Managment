var __defProp = Object.defineProperty;
var __name = (target, value) => __defProp(target, "name", { value, configurable: true });

// node_modules/unenv/dist/runtime/_internal/utils.mjs
// @__NO_SIDE_EFFECTS__
function createNotImplementedError(name) {
  return new Error(`[unenv] ${name} is not implemented yet!`);
}
__name(createNotImplementedError, "createNotImplementedError");
// @__NO_SIDE_EFFECTS__
function notImplemented(name) {
  const fn = /* @__PURE__ */ __name(() => {
    throw /* @__PURE__ */ createNotImplementedError(name);
  }, "fn");
  return Object.assign(fn, { __unenv__: true });
}
__name(notImplemented, "notImplemented");
// @__NO_SIDE_EFFECTS__
function notImplementedClass(name) {
  return class {
    __unenv__ = true;
    constructor() {
      throw new Error(`[unenv] ${name} is not implemented yet!`);
    }
  };
}
__name(notImplementedClass, "notImplementedClass");

// node_modules/unenv/dist/runtime/node/internal/perf_hooks/performance.mjs
var _timeOrigin = globalThis.performance?.timeOrigin ?? Date.now();
var _performanceNow = globalThis.performance?.now ? globalThis.performance.now.bind(globalThis.performance) : () => Date.now() - _timeOrigin;
var nodeTiming = {
  name: "node",
  entryType: "node",
  startTime: 0,
  duration: 0,
  nodeStart: 0,
  v8Start: 0,
  bootstrapComplete: 0,
  environment: 0,
  loopStart: 0,
  loopExit: 0,
  idleTime: 0,
  uvMetricsInfo: {
    loopCount: 0,
    events: 0,
    eventsWaiting: 0
  },
  detail: void 0,
  toJSON() {
    return this;
  }
};
var PerformanceEntry = class {
  static {
    __name(this, "PerformanceEntry");
  }
  __unenv__ = true;
  detail;
  entryType = "event";
  name;
  startTime;
  constructor(name, options) {
    this.name = name;
    this.startTime = options?.startTime || _performanceNow();
    this.detail = options?.detail;
  }
  get duration() {
    return _performanceNow() - this.startTime;
  }
  toJSON() {
    return {
      name: this.name,
      entryType: this.entryType,
      startTime: this.startTime,
      duration: this.duration,
      detail: this.detail
    };
  }
};
var PerformanceMark = class PerformanceMark2 extends PerformanceEntry {
  static {
    __name(this, "PerformanceMark");
  }
  entryType = "mark";
  constructor() {
    super(...arguments);
  }
  get duration() {
    return 0;
  }
};
var PerformanceMeasure = class extends PerformanceEntry {
  static {
    __name(this, "PerformanceMeasure");
  }
  entryType = "measure";
};
var PerformanceResourceTiming = class extends PerformanceEntry {
  static {
    __name(this, "PerformanceResourceTiming");
  }
  entryType = "resource";
  serverTiming = [];
  connectEnd = 0;
  connectStart = 0;
  decodedBodySize = 0;
  domainLookupEnd = 0;
  domainLookupStart = 0;
  encodedBodySize = 0;
  fetchStart = 0;
  initiatorType = "";
  name = "";
  nextHopProtocol = "";
  redirectEnd = 0;
  redirectStart = 0;
  requestStart = 0;
  responseEnd = 0;
  responseStart = 0;
  secureConnectionStart = 0;
  startTime = 0;
  transferSize = 0;
  workerStart = 0;
  responseStatus = 0;
};
var PerformanceObserverEntryList = class {
  static {
    __name(this, "PerformanceObserverEntryList");
  }
  __unenv__ = true;
  getEntries() {
    return [];
  }
  getEntriesByName(_name, _type) {
    return [];
  }
  getEntriesByType(type) {
    return [];
  }
};
var Performance = class {
  static {
    __name(this, "Performance");
  }
  __unenv__ = true;
  timeOrigin = _timeOrigin;
  eventCounts = /* @__PURE__ */ new Map();
  _entries = [];
  _resourceTimingBufferSize = 0;
  navigation = void 0;
  timing = void 0;
  timerify(_fn, _options) {
    throw createNotImplementedError("Performance.timerify");
  }
  get nodeTiming() {
    return nodeTiming;
  }
  eventLoopUtilization() {
    return {};
  }
  markResourceTiming() {
    return new PerformanceResourceTiming("");
  }
  onresourcetimingbufferfull = null;
  now() {
    if (this.timeOrigin === _timeOrigin) {
      return _performanceNow();
    }
    return Date.now() - this.timeOrigin;
  }
  clearMarks(markName) {
    this._entries = markName ? this._entries.filter((e) => e.name !== markName) : this._entries.filter((e) => e.entryType !== "mark");
  }
  clearMeasures(measureName) {
    this._entries = measureName ? this._entries.filter((e) => e.name !== measureName) : this._entries.filter((e) => e.entryType !== "measure");
  }
  clearResourceTimings() {
    this._entries = this._entries.filter((e) => e.entryType !== "resource" || e.entryType !== "navigation");
  }
  getEntries() {
    return this._entries;
  }
  getEntriesByName(name, type) {
    return this._entries.filter((e) => e.name === name && (!type || e.entryType === type));
  }
  getEntriesByType(type) {
    return this._entries.filter((e) => e.entryType === type);
  }
  mark(name, options) {
    const entry = new PerformanceMark(name, options);
    this._entries.push(entry);
    return entry;
  }
  measure(measureName, startOrMeasureOptions, endMark) {
    let start;
    let end;
    if (typeof startOrMeasureOptions === "string") {
      start = this.getEntriesByName(startOrMeasureOptions, "mark")[0]?.startTime;
      end = this.getEntriesByName(endMark, "mark")[0]?.startTime;
    } else {
      start = Number.parseFloat(startOrMeasureOptions?.start) || this.now();
      end = Number.parseFloat(startOrMeasureOptions?.end) || this.now();
    }
    const entry = new PerformanceMeasure(measureName, {
      startTime: start,
      detail: {
        start,
        end
      }
    });
    this._entries.push(entry);
    return entry;
  }
  setResourceTimingBufferSize(maxSize) {
    this._resourceTimingBufferSize = maxSize;
  }
  addEventListener(type, listener, options) {
    throw createNotImplementedError("Performance.addEventListener");
  }
  removeEventListener(type, listener, options) {
    throw createNotImplementedError("Performance.removeEventListener");
  }
  dispatchEvent(event) {
    throw createNotImplementedError("Performance.dispatchEvent");
  }
  toJSON() {
    return this;
  }
};
var PerformanceObserver = class {
  static {
    __name(this, "PerformanceObserver");
  }
  __unenv__ = true;
  static supportedEntryTypes = [];
  _callback = null;
  constructor(callback) {
    this._callback = callback;
  }
  takeRecords() {
    return [];
  }
  disconnect() {
    throw createNotImplementedError("PerformanceObserver.disconnect");
  }
  observe(options) {
    throw createNotImplementedError("PerformanceObserver.observe");
  }
  bind(fn) {
    return fn;
  }
  runInAsyncScope(fn, thisArg, ...args) {
    return fn.call(thisArg, ...args);
  }
  asyncId() {
    return 0;
  }
  triggerAsyncId() {
    return 0;
  }
  emitDestroy() {
    return this;
  }
};
var performance = globalThis.performance && "addEventListener" in globalThis.performance ? globalThis.performance : new Performance();

// node_modules/@cloudflare/unenv-preset/dist/runtime/polyfill/performance.mjs
if (!("__unenv__" in performance)) {
  const proto = Performance.prototype;
  for (const key of Object.getOwnPropertyNames(proto)) {
    if (key !== "constructor" && !(key in performance)) {
      const desc = Object.getOwnPropertyDescriptor(proto, key);
      if (desc) {
        Object.defineProperty(performance, key, desc);
      }
    }
  }
}
globalThis.performance = performance;
globalThis.Performance = Performance;
globalThis.PerformanceEntry = PerformanceEntry;
globalThis.PerformanceMark = PerformanceMark;
globalThis.PerformanceMeasure = PerformanceMeasure;
globalThis.PerformanceObserver = PerformanceObserver;
globalThis.PerformanceObserverEntryList = PerformanceObserverEntryList;
globalThis.PerformanceResourceTiming = PerformanceResourceTiming;

// node_modules/unenv/dist/runtime/node/console.mjs
import { Writable } from "node:stream";

// node_modules/unenv/dist/runtime/mock/noop.mjs
var noop_default = Object.assign(() => {
}, { __unenv__: true });

// node_modules/unenv/dist/runtime/node/console.mjs
var _console = globalThis.console;
var _ignoreErrors = true;
var _stderr = new Writable();
var _stdout = new Writable();
var log = _console?.log ?? noop_default;
var info = _console?.info ?? log;
var trace = _console?.trace ?? info;
var debug = _console?.debug ?? log;
var table = _console?.table ?? log;
var error = _console?.error ?? log;
var warn = _console?.warn ?? error;
var createTask = _console?.createTask ?? /* @__PURE__ */ notImplemented("console.createTask");
var clear = _console?.clear ?? noop_default;
var count = _console?.count ?? noop_default;
var countReset = _console?.countReset ?? noop_default;
var dir = _console?.dir ?? noop_default;
var dirxml = _console?.dirxml ?? noop_default;
var group = _console?.group ?? noop_default;
var groupEnd = _console?.groupEnd ?? noop_default;
var groupCollapsed = _console?.groupCollapsed ?? noop_default;
var profile = _console?.profile ?? noop_default;
var profileEnd = _console?.profileEnd ?? noop_default;
var time = _console?.time ?? noop_default;
var timeEnd = _console?.timeEnd ?? noop_default;
var timeLog = _console?.timeLog ?? noop_default;
var timeStamp = _console?.timeStamp ?? noop_default;
var Console = _console?.Console ?? /* @__PURE__ */ notImplementedClass("console.Console");
var _times = /* @__PURE__ */ new Map();
var _stdoutErrorHandler = noop_default;
var _stderrErrorHandler = noop_default;

// node_modules/@cloudflare/unenv-preset/dist/runtime/node/console.mjs
var workerdConsole = globalThis["console"];
var {
  assert,
  clear: clear2,
  // @ts-expect-error undocumented public API
  context,
  count: count2,
  countReset: countReset2,
  // @ts-expect-error undocumented public API
  createTask: createTask2,
  debug: debug2,
  dir: dir2,
  dirxml: dirxml2,
  error: error2,
  group: group2,
  groupCollapsed: groupCollapsed2,
  groupEnd: groupEnd2,
  info: info2,
  log: log2,
  profile: profile2,
  profileEnd: profileEnd2,
  table: table2,
  time: time2,
  timeEnd: timeEnd2,
  timeLog: timeLog2,
  timeStamp: timeStamp2,
  trace: trace2,
  warn: warn2
} = workerdConsole;
Object.assign(workerdConsole, {
  Console,
  _ignoreErrors,
  _stderr,
  _stderrErrorHandler,
  _stdout,
  _stdoutErrorHandler,
  _times
});
var console_default = workerdConsole;

// node_modules/wrangler/_virtual_unenv_global_polyfill-@cloudflare-unenv-preset-node-console
globalThis.console = console_default;

// node_modules/unenv/dist/runtime/node/internal/process/hrtime.mjs
var hrtime = /* @__PURE__ */ Object.assign(/* @__PURE__ */ __name(function hrtime2(startTime) {
  const now = Date.now();
  const seconds = Math.trunc(now / 1e3);
  const nanos = now % 1e3 * 1e6;
  if (startTime) {
    let diffSeconds = seconds - startTime[0];
    let diffNanos = nanos - startTime[0];
    if (diffNanos < 0) {
      diffSeconds = diffSeconds - 1;
      diffNanos = 1e9 + diffNanos;
    }
    return [diffSeconds, diffNanos];
  }
  return [seconds, nanos];
}, "hrtime"), { bigint: /* @__PURE__ */ __name(function bigint() {
  return BigInt(Date.now() * 1e6);
}, "bigint") });

// node_modules/unenv/dist/runtime/node/internal/process/process.mjs
import { EventEmitter } from "node:events";

// node_modules/unenv/dist/runtime/node/internal/tty/read-stream.mjs
var ReadStream = class {
  static {
    __name(this, "ReadStream");
  }
  fd;
  isRaw = false;
  isTTY = false;
  constructor(fd) {
    this.fd = fd;
  }
  setRawMode(mode) {
    this.isRaw = mode;
    return this;
  }
};

// node_modules/unenv/dist/runtime/node/internal/tty/write-stream.mjs
var WriteStream = class {
  static {
    __name(this, "WriteStream");
  }
  fd;
  columns = 80;
  rows = 24;
  isTTY = false;
  constructor(fd) {
    this.fd = fd;
  }
  clearLine(dir3, callback) {
    callback && callback();
    return false;
  }
  clearScreenDown(callback) {
    callback && callback();
    return false;
  }
  cursorTo(x, y, callback) {
    callback && typeof callback === "function" && callback();
    return false;
  }
  moveCursor(dx, dy, callback) {
    callback && callback();
    return false;
  }
  getColorDepth(env2) {
    return 1;
  }
  hasColors(count3, env2) {
    return false;
  }
  getWindowSize() {
    return [this.columns, this.rows];
  }
  write(str, encoding, cb) {
    if (str instanceof Uint8Array) {
      str = new TextDecoder().decode(str);
    }
    try {
      console.log(str);
    } catch {
    }
    cb && typeof cb === "function" && cb();
    return false;
  }
};

// node_modules/unenv/dist/runtime/node/internal/process/node-version.mjs
var NODE_VERSION = "22.14.0";

// node_modules/unenv/dist/runtime/node/internal/process/process.mjs
var Process = class _Process extends EventEmitter {
  static {
    __name(this, "Process");
  }
  env;
  hrtime;
  nextTick;
  constructor(impl) {
    super();
    this.env = impl.env;
    this.hrtime = impl.hrtime;
    this.nextTick = impl.nextTick;
    for (const prop of [...Object.getOwnPropertyNames(_Process.prototype), ...Object.getOwnPropertyNames(EventEmitter.prototype)]) {
      const value = this[prop];
      if (typeof value === "function") {
        this[prop] = value.bind(this);
      }
    }
  }
  // --- event emitter ---
  emitWarning(warning, type, code) {
    console.warn(`${code ? `[${code}] ` : ""}${type ? `${type}: ` : ""}${warning}`);
  }
  emit(...args) {
    return super.emit(...args);
  }
  listeners(eventName) {
    return super.listeners(eventName);
  }
  // --- stdio (lazy initializers) ---
  #stdin;
  #stdout;
  #stderr;
  get stdin() {
    return this.#stdin ??= new ReadStream(0);
  }
  get stdout() {
    return this.#stdout ??= new WriteStream(1);
  }
  get stderr() {
    return this.#stderr ??= new WriteStream(2);
  }
  // --- cwd ---
  #cwd = "/";
  chdir(cwd2) {
    this.#cwd = cwd2;
  }
  cwd() {
    return this.#cwd;
  }
  // --- dummy props and getters ---
  arch = "";
  platform = "";
  argv = [];
  argv0 = "";
  execArgv = [];
  execPath = "";
  title = "";
  pid = 200;
  ppid = 100;
  get version() {
    return `v${NODE_VERSION}`;
  }
  get versions() {
    return { node: NODE_VERSION };
  }
  get allowedNodeEnvironmentFlags() {
    return /* @__PURE__ */ new Set();
  }
  get sourceMapsEnabled() {
    return false;
  }
  get debugPort() {
    return 0;
  }
  get throwDeprecation() {
    return false;
  }
  get traceDeprecation() {
    return false;
  }
  get features() {
    return {};
  }
  get release() {
    return {};
  }
  get connected() {
    return false;
  }
  get config() {
    return {};
  }
  get moduleLoadList() {
    return [];
  }
  constrainedMemory() {
    return 0;
  }
  availableMemory() {
    return 0;
  }
  uptime() {
    return 0;
  }
  resourceUsage() {
    return {};
  }
  // --- noop methods ---
  ref() {
  }
  unref() {
  }
  // --- unimplemented methods ---
  umask() {
    throw createNotImplementedError("process.umask");
  }
  getBuiltinModule() {
    return void 0;
  }
  getActiveResourcesInfo() {
    throw createNotImplementedError("process.getActiveResourcesInfo");
  }
  exit() {
    throw createNotImplementedError("process.exit");
  }
  reallyExit() {
    throw createNotImplementedError("process.reallyExit");
  }
  kill() {
    throw createNotImplementedError("process.kill");
  }
  abort() {
    throw createNotImplementedError("process.abort");
  }
  dlopen() {
    throw createNotImplementedError("process.dlopen");
  }
  setSourceMapsEnabled() {
    throw createNotImplementedError("process.setSourceMapsEnabled");
  }
  loadEnvFile() {
    throw createNotImplementedError("process.loadEnvFile");
  }
  disconnect() {
    throw createNotImplementedError("process.disconnect");
  }
  cpuUsage() {
    throw createNotImplementedError("process.cpuUsage");
  }
  setUncaughtExceptionCaptureCallback() {
    throw createNotImplementedError("process.setUncaughtExceptionCaptureCallback");
  }
  hasUncaughtExceptionCaptureCallback() {
    throw createNotImplementedError("process.hasUncaughtExceptionCaptureCallback");
  }
  initgroups() {
    throw createNotImplementedError("process.initgroups");
  }
  openStdin() {
    throw createNotImplementedError("process.openStdin");
  }
  assert() {
    throw createNotImplementedError("process.assert");
  }
  binding() {
    throw createNotImplementedError("process.binding");
  }
  // --- attached interfaces ---
  permission = { has: /* @__PURE__ */ notImplemented("process.permission.has") };
  report = {
    directory: "",
    filename: "",
    signal: "SIGUSR2",
    compact: false,
    reportOnFatalError: false,
    reportOnSignal: false,
    reportOnUncaughtException: false,
    getReport: /* @__PURE__ */ notImplemented("process.report.getReport"),
    writeReport: /* @__PURE__ */ notImplemented("process.report.writeReport")
  };
  finalization = {
    register: /* @__PURE__ */ notImplemented("process.finalization.register"),
    unregister: /* @__PURE__ */ notImplemented("process.finalization.unregister"),
    registerBeforeExit: /* @__PURE__ */ notImplemented("process.finalization.registerBeforeExit")
  };
  memoryUsage = Object.assign(() => ({
    arrayBuffers: 0,
    rss: 0,
    external: 0,
    heapTotal: 0,
    heapUsed: 0
  }), { rss: /* @__PURE__ */ __name(() => 0, "rss") });
  // --- undefined props ---
  mainModule = void 0;
  domain = void 0;
  // optional
  send = void 0;
  exitCode = void 0;
  channel = void 0;
  getegid = void 0;
  geteuid = void 0;
  getgid = void 0;
  getgroups = void 0;
  getuid = void 0;
  setegid = void 0;
  seteuid = void 0;
  setgid = void 0;
  setgroups = void 0;
  setuid = void 0;
  // internals
  _events = void 0;
  _eventsCount = void 0;
  _exiting = void 0;
  _maxListeners = void 0;
  _debugEnd = void 0;
  _debugProcess = void 0;
  _fatalException = void 0;
  _getActiveHandles = void 0;
  _getActiveRequests = void 0;
  _kill = void 0;
  _preload_modules = void 0;
  _rawDebug = void 0;
  _startProfilerIdleNotifier = void 0;
  _stopProfilerIdleNotifier = void 0;
  _tickCallback = void 0;
  _disconnect = void 0;
  _handleQueue = void 0;
  _pendingMessage = void 0;
  _channel = void 0;
  _send = void 0;
  _linkedBinding = void 0;
};

// node_modules/@cloudflare/unenv-preset/dist/runtime/node/process.mjs
var globalProcess = globalThis["process"];
var getBuiltinModule = globalProcess.getBuiltinModule;
var workerdProcess = getBuiltinModule("node:process");
var unenvProcess = new Process({
  env: globalProcess.env,
  hrtime,
  // `nextTick` is available from workerd process v1
  nextTick: workerdProcess.nextTick
});
var { exit, features, platform } = workerdProcess;
var {
  _channel,
  _debugEnd,
  _debugProcess,
  _disconnect,
  _events,
  _eventsCount,
  _exiting,
  _fatalException,
  _getActiveHandles,
  _getActiveRequests,
  _handleQueue,
  _kill,
  _linkedBinding,
  _maxListeners,
  _pendingMessage,
  _preload_modules,
  _rawDebug,
  _send,
  _startProfilerIdleNotifier,
  _stopProfilerIdleNotifier,
  _tickCallback,
  abort,
  addListener,
  allowedNodeEnvironmentFlags,
  arch,
  argv,
  argv0,
  assert: assert2,
  availableMemory,
  binding,
  channel,
  chdir,
  config,
  connected,
  constrainedMemory,
  cpuUsage,
  cwd,
  debugPort,
  disconnect,
  dlopen,
  domain,
  emit,
  emitWarning,
  env,
  eventNames,
  execArgv,
  execPath,
  exitCode,
  finalization,
  getActiveResourcesInfo,
  getegid,
  geteuid,
  getgid,
  getgroups,
  getMaxListeners,
  getuid,
  hasUncaughtExceptionCaptureCallback,
  hrtime: hrtime3,
  initgroups,
  kill,
  listenerCount,
  listeners,
  loadEnvFile,
  mainModule,
  memoryUsage,
  moduleLoadList,
  nextTick,
  off,
  on,
  once,
  openStdin,
  permission,
  pid,
  ppid,
  prependListener,
  prependOnceListener,
  rawListeners,
  reallyExit,
  ref,
  release,
  removeAllListeners,
  removeListener,
  report,
  resourceUsage,
  send,
  setegid,
  seteuid,
  setgid,
  setgroups,
  setMaxListeners,
  setSourceMapsEnabled,
  setuid,
  setUncaughtExceptionCaptureCallback,
  sourceMapsEnabled,
  stderr,
  stdin,
  stdout,
  throwDeprecation,
  title,
  traceDeprecation,
  umask,
  unref,
  uptime,
  version,
  versions
} = unenvProcess;
var _process = {
  abort,
  addListener,
  allowedNodeEnvironmentFlags,
  hasUncaughtExceptionCaptureCallback,
  setUncaughtExceptionCaptureCallback,
  loadEnvFile,
  sourceMapsEnabled,
  arch,
  argv,
  argv0,
  chdir,
  config,
  connected,
  constrainedMemory,
  availableMemory,
  cpuUsage,
  cwd,
  debugPort,
  dlopen,
  disconnect,
  emit,
  emitWarning,
  env,
  eventNames,
  execArgv,
  execPath,
  exit,
  finalization,
  features,
  getBuiltinModule,
  getActiveResourcesInfo,
  getMaxListeners,
  hrtime: hrtime3,
  kill,
  listeners,
  listenerCount,
  memoryUsage,
  nextTick,
  on,
  off,
  once,
  pid,
  platform,
  ppid,
  prependListener,
  prependOnceListener,
  rawListeners,
  release,
  removeAllListeners,
  removeListener,
  report,
  resourceUsage,
  setMaxListeners,
  setSourceMapsEnabled,
  stderr,
  stdin,
  stdout,
  title,
  throwDeprecation,
  traceDeprecation,
  umask,
  uptime,
  version,
  versions,
  // @ts-expect-error old API
  domain,
  initgroups,
  moduleLoadList,
  reallyExit,
  openStdin,
  assert: assert2,
  binding,
  send,
  exitCode,
  channel,
  getegid,
  geteuid,
  getgid,
  getgroups,
  getuid,
  setegid,
  seteuid,
  setgid,
  setgroups,
  setuid,
  permission,
  mainModule,
  _events,
  _eventsCount,
  _exiting,
  _maxListeners,
  _debugEnd,
  _debugProcess,
  _fatalException,
  _getActiveHandles,
  _getActiveRequests,
  _kill,
  _preload_modules,
  _rawDebug,
  _startProfilerIdleNotifier,
  _stopProfilerIdleNotifier,
  _tickCallback,
  _disconnect,
  _handleQueue,
  _pendingMessage,
  _channel,
  _send,
  _linkedBinding
};
var process_default = _process;

// node_modules/wrangler/_virtual_unenv_global_polyfill-@cloudflare-unenv-preset-node-process
globalThis.process = process_default;

// src/agents.ts
var AGENTS = [
  {
    slug: "range-operations",
    title: "Range Operations",
    description: "Lane utilisation, RSO cover, rentals, incidents and maintenance.",
    brief: "You handle the range floor: lane bookings and utilisation, RSO shift cover, ammo and firearm rentals, brass buyback, safety incidents and lane maintenance. When a firearm rental is outstanding or a lane is out of service, say so plainly and name the record. Never advise issuing a rental firearm without a verified photo ID and a signed waiver \u2014 that is a hard rule, not a preference.",
    sources: ["range_ops", "lane_reservations", "waivers", "incidents"],
    taskLabel: "What should I look at on the range today?"
  },
  {
    slug: "compliance-officer",
    title: "Compliance Officer",
    description: "ATF bound book, Form 4473, NICS queue, state rules and audit readiness.",
    brief: "You cover federal and state firearms compliance: the A&D bound book, Form 4473 completeness, the NICS/NTN queue, out-of-state transfer policy and audit readiness. Be conservative and specific. If a question touches whether a transfer may legally proceed, state what the record shows and what is missing \u2014 then say the decision belongs to a licensed person, because it does. Never assert that a sale is cleared unless the compliance state actually says so.",
    sources: ["bound_book", "forms_4473", "nics", "state_rules", "compliance_calendar"],
    taskLabel: "What compliance items need attention?"
  },
  {
    slug: "inventory-buyer",
    title: "Inventory & Purchasing",
    description: "Stock levels, reorder points, wholesaler pricing, POs and drop-ship.",
    brief: "You watch stock and buying: what is short, what is dead, reorder points, wholesaler price drift, open purchase orders, backorders and drop-ship routing. Quantify \u2014 a recommendation to reorder should carry the SKU, the count on hand and the source of the number.",
    sources: ["inventory", "purchase_orders", "wholesalers", "map_pricing", "cycle_counts"],
    taskLabel: "What should I reorder this week?"
  },
  {
    slug: "sales-analyst",
    title: "Sales Analyst",
    description: "Revenue, margin, best and slow sellers, register and tender performance.",
    brief: "You read the money: revenue by period and channel, margin, best and slow sellers, discounting, tender mix and register variance. Always state the period a figure covers. If two sources disagree, say which you used and that they disagree, rather than silently picking one.",
    sources: ["orders", "kpis", "reports", "woocommerce"],
    taskLabel: "How did we trade this month?"
  },
  {
    slug: "membership-growth",
    title: "Membership & Retention",
    description: "Plans, renewals, churn, guest passes and lane entitlements.",
    brief: "You own membership: active plans, renewals due, churn signals, guest passes and which booking types a plan actually entitles a member to. Members and walk-ins are charged differently \u2014 when a lane fee looks wrong, check entitlement before assuming a pricing error.",
    sources: ["membership", "bookings", "crm"],
    taskLabel: "Who is due to renew, and who is at risk?"
  },
  {
    slug: "customer-care",
    title: "Customer Care",
    description: "Enquiries, order status, repairs, layaway and consignment follow-up.",
    brief: "You answer for the customer: order and transfer status, repair tickets, layaway balances, consignment payouts and open enquiries. Draft replies in a plain, warm, unhurried voice. Never promise a date the records do not support.",
    sources: ["orders", "repairs", "layaways", "consignments", "crm", "messaging"],
    taskLabel: "Draft a reply to this customer"
  },
  {
    slug: "marketing-seo",
    title: "Marketing & SEO",
    description: "Search performance, site speed, content gaps and campaign results.",
    brief: "You cover acquisition: Search Console impressions and positions, PageSpeed and Core Web Vitals, content gaps, and campaign results against revenue. Tie every recommendation to a measured number, not to general SEO advice.",
    sources: ["gsc", "pagespeed", "analytics", "seo"],
    taskLabel: "Where are we losing search traffic?"
  },
  {
    slug: "training-classes",
    title: "Training & Classes",
    description: "Class scheduling, enrolment, instructor cover and CCW course admin.",
    brief: "You run the training side: class calendar, enrolment and rosters, instructor availability, prerequisites and CCW course administration. Flag under-enrolled sessions early enough to act.",
    sources: ["classes", "bookings", "crm"],
    taskLabel: "How are classes filling up?"
  },
  {
    slug: "daily-briefing",
    title: "Daily Briefing",
    description: "One cross-business summary: what happened, what needs a decision today.",
    brief: "You produce the morning briefing across the whole business. Lead with anything that needs a decision today, then yesterday in numbers, then what is trending. Be short. An operator reads this standing up with a coffee \u2014 no preamble, no restating the question.",
    sources: ["orders", "kpis", "range_ops", "compliance_calendar", "membership", "inventory"],
    taskLabel: "Give me today\u2019s briefing"
  }
];
function findAgent(slug) {
  return AGENTS.find((a) => a.slug === slug);
}
__name(findAgent, "findAgent");

// src/prompt.ts
var BASE = `You are the WPISTIC AI Helper working inside the Guns2Ammo business operations dashboard.
Guns2Ammo is a firearms retailer with an indoor shooting range, a training school, memberships and an online store.
You are speaking to the owner or a member of staff, not to a customer.

HOW TO ANSWER
- Answer from the CONTEXT below. The context is retrieved from Guns2Ammo's own records.
- If the context does not contain the answer, say so directly \u2014 "I don't have that in the knowledge base" \u2014 and say what you would need. Never fill a gap with a plausible guess.
- Never invent a number, a date, a serial number, a stock level, an order id or a compliance state. A number you did not read in the context does not go in your answer.
- When you use a figure, say where it came from and what period it covers.
- If two pieces of context disagree, say so rather than silently choosing one.
- Be brief and concrete. Staff read this between customers.

FIREARMS AND COMPLIANCE
- You do not authorise transfers, approve background checks, or decide whether a sale may legally proceed. You report what the records show and what is missing; a licensed person decides.
- Never suggest a workaround to a compliance control, a waiting period, or an identity or waiver requirement, even if asked directly.

You have no ability to change anything. You read and advise; staff act.`;
function buildSystemPrompt({ agent, results, facts }) {
  const parts = [BASE];
  if (agent) {
    parts.push(`
YOUR ROLE \u2014 ${agent.title}
${agent.brief}`);
  }
  if (facts && facts.trim() !== "") {
    parts.push(`
LIVE FIGURES (current, from the dashboard)
${facts.trim().slice(0, 8e3)}`);
  }
  if (results.length === 0) {
    parts.push(
      "\nCONTEXT\nNothing was retrieved from the knowledge base for this question. Say that you do not have it rather than answering from general knowledge \u2014 unless the question is small talk or about how to use the dashboard itself."
    );
  } else {
    const context2 = results.map((r, i) => {
      const label = r.label || r.source_uri || `document ${r.doc_id}`;
      return `[${i + 1}] ${label}
${r.text}`;
    }).join("\n\n");
    parts.push(
      `
CONTEXT (${results.length} passage${results.length === 1 ? "" : "s"} from the Guns2Ammo knowledge base)
${context2}

Cite the passage number in square brackets when a statement rests on one.`
    );
  }
  return parts.join("\n");
}
__name(buildSystemPrompt, "buildSystemPrompt");

// src/rag.ts
async function retrieve(env2, query, k = 6, timeoutMs = 8e3) {
  if (!env2.RAG_URL || !env2.RAG_TOKEN) {
    return { results: [], degraded: true, reason: "rag_not_configured" };
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(`${env2.RAG_URL.replace(/\/$/, "")}/query`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${env2.RAG_TOKEN}`
      },
      body: JSON.stringify({ query, k }),
      signal: controller.signal
    });
    if (!res.ok) {
      return { results: [], degraded: true, reason: `rag_http_${res.status}` };
    }
    const body = await res.json();
    if (!body.ok || !Array.isArray(body.results)) {
      return { results: [], degraded: true, reason: body.error || "rag_bad_payload" };
    }
    return { results: body.results, degraded: false };
  } catch (err) {
    const reason = err instanceof Error && err.name === "AbortError" ? "rag_timeout" : "rag_unreachable";
    return { results: [], degraded: true, reason };
  } finally {
    clearTimeout(timer);
  }
}
__name(retrieve, "retrieve");

// src/index.ts
var MODEL = "@cf/meta/llama-3.3-70b-instruct-fp8-fast";
var MAX_TURNS = 20;
var MAX_CHARS_PER_TURN = 6e3;
function json(body, status = 200, extra = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json", ...extra }
  });
}
__name(json, "json");
function timingSafeEqual(a, b) {
  const enc = new TextEncoder();
  const ab = enc.encode(a);
  const bb = enc.encode(b);
  if (ab.length !== bb.length) return false;
  let diff = 0;
  for (let i = 0; i < ab.length; i++) diff |= ab[i] ^ bb[i];
  return diff === 0;
}
__name(timingSafeEqual, "timingSafeEqual");
function authorized(request, env2) {
  if (!env2.API_KEY) return false;
  const header = request.headers.get("Authorization") ?? "";
  if (!header.startsWith("Bearer ")) return false;
  return timingSafeEqual(header.slice("Bearer ".length).trim(), env2.API_KEY);
}
__name(authorized, "authorized");
function corsHeaders(request, env2) {
  const origin = request.headers.get("Origin");
  if (!origin || !env2.ALLOWED_ORIGINS) return {};
  const allowed = env2.ALLOWED_ORIGINS.split(",").map((o) => o.trim()).filter(Boolean);
  if (!allowed.includes(origin)) return {};
  return {
    "Access-Control-Allow-Origin": origin,
    "Access-Control-Allow-Headers": "Authorization, Content-Type",
    "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
    "Access-Control-Expose-Headers": "x-g2a-agent, x-g2a-passages, x-g2a-degraded",
    Vary: "Origin"
  };
}
__name(corsHeaders, "corsHeaders");
async function readJson(request) {
  try {
    const body = await request.json();
    return body && typeof body === "object" ? body : {};
  } catch {
    return {};
  }
}
__name(readJson, "readJson");
function parseMessages(raw) {
  if (!Array.isArray(raw)) return [];
  return raw.filter(
    (m) => !!m && typeof m === "object" && typeof m.content === "string" && (m.role === "user" || m.role === "assistant")
  ).slice(-MAX_TURNS).map((m) => ({ role: m.role, content: m.content.slice(0, MAX_CHARS_PER_TURN) }));
}
__name(parseMessages, "parseMessages");
function handleAgents(cors) {
  return json(
    {
      ok: true,
      agents: AGENTS.map((a) => ({
        slug: a.slug,
        title: a.title,
        description: a.description,
        task_label: a.taskLabel,
        sources: a.sources
      }))
    },
    200,
    cors
  );
}
__name(handleAgents, "handleAgents");
async function generate(env2, messages, agent, facts, cors) {
  const lastUser = [...messages].reverse().find((m) => m.role === "user")?.content ?? "";
  const { results, degraded, reason } = await retrieve(env2, lastUser);
  const system = buildSystemPrompt({ agent, results, facts });
  const stream = await env2.AI.run(
    MODEL,
    {
      messages: [{ role: "system", content: system }, ...messages],
      max_tokens: 1024,
      stream: true
    },
    env2.AI_GATEWAY_ID ? { gateway: { id: env2.AI_GATEWAY_ID, skipCache: false, cacheTtl: 900 } } : {}
  );
  return new Response(stream, {
    headers: {
      "content-type": "text/event-stream; charset=utf-8",
      "cache-control": "no-cache",
      connection: "keep-alive",
      // Metadata rides on headers so the UI can show which agent answered
      // and how many passages backed it without parsing model prose. The
      // degraded flag lets the bubble tell the operator the answer was
      // produced without the knowledge base, which changes how much they
      // should trust it.
      "x-g2a-agent": agent?.slug ?? "",
      "x-g2a-passages": String(results.length),
      "x-g2a-degraded": degraded ? reason || "retrieval_unavailable" : "",
      ...cors
    }
  });
}
__name(generate, "generate");
async function handleChat(request, env2, cors) {
  const body = await readJson(request);
  const messages = parseMessages(body.messages);
  if (messages.length === 0) {
    return json({ ok: false, error: "messages[] required" }, 400, cors);
  }
  const slug = typeof body.agent === "string" ? body.agent : "";
  const agent = slug ? findAgent(slug) : void 0;
  if (slug && !agent) {
    return json({ ok: false, error: `unknown agent: ${slug}` }, 400, cors);
  }
  const facts = typeof body.facts === "string" ? body.facts : void 0;
  return generate(env2, messages, agent, facts, cors);
}
__name(handleChat, "handleChat");
async function handleAgentRun(request, env2, slug, cors) {
  const agent = findAgent(slug);
  if (!agent) {
    return json({ ok: false, error: `unknown agent: ${slug}` }, 404, cors);
  }
  const body = await readJson(request);
  const task = typeof body.task === "string" ? body.task.trim() : "";
  if (task === "") {
    return json({ ok: false, error: "task required" }, 400, cors);
  }
  const facts = typeof body.facts === "string" ? body.facts : void 0;
  return generate(
    env2,
    [{ role: "user", content: task.slice(0, MAX_CHARS_PER_TURN) }],
    agent,
    facts,
    cors
  );
}
__name(handleAgentRun, "handleAgentRun");
var index_default = {
  async fetch(request, env2) {
    const url = new URL(request.url);
    const cors = corsHeaders(request, env2);
    if (request.method === "OPTIONS") {
      return new Response(null, { status: 204, headers: cors });
    }
    if (url.pathname === "/health" && request.method === "GET") {
      return json(
        {
          ok: true,
          configured: {
            api_key: Boolean(env2.API_KEY),
            rag: Boolean(env2.RAG_URL && env2.RAG_TOKEN),
            ai_gateway: Boolean(env2.AI_GATEWAY_ID)
          },
          agents: AGENTS.length
        },
        200,
        cors
      );
    }
    if (!authorized(request, env2)) {
      return json({ ok: false, error: "unauthorized" }, 401, cors);
    }
    try {
      if (url.pathname === "/api/agents" && request.method === "GET") {
        return handleAgents(cors);
      }
      if (url.pathname === "/api/chat" && request.method === "POST") {
        return await handleChat(request, env2, cors);
      }
      const run = url.pathname.match(/^\/api\/agents\/([a-z0-9-]+)\/run$/);
      if (run && request.method === "POST") {
        return await handleAgentRun(request, env2, run[1], cors);
      }
      return json({ ok: false, error: "not_found" }, 404, cors);
    } catch (err) {
      return json(
        { ok: false, error: err instanceof Error ? err.message : "internal_error" },
        500,
        cors
      );
    }
  }
};
export {
  index_default as default
};
//# sourceMappingURL=index.js.map
