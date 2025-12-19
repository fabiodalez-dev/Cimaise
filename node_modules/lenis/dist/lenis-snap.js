// packages/snap/src/debounce.ts
function debounce(callback, delay) {
  let timer;
  return function(...args) {
    let context = this;
    clearTimeout(timer);
    timer = setTimeout(() => {
      timer = void 0;
      callback.apply(context, args);
    }, delay);
  };
}

// packages/snap/src/element.ts
function removeParentSticky(element) {
  const position = getComputedStyle(element).position;
  const isSticky = position === "sticky";
  if (isSticky) {
    element.style.setProperty("position", "static");
    element.dataset.sticky = "true";
  }
  if (element.offsetParent) {
    removeParentSticky(element.offsetParent);
  }
}
function addParentSticky(element) {
  if (element?.dataset?.sticky === "true") {
    element.style.removeProperty("position");
    delete element.dataset.sticky;
  }
  if (element.offsetParent) {
    addParentSticky(element.offsetParent);
  }
}
function offsetTop(element, accumulator = 0) {
  const top = accumulator + element.offsetTop;
  if (element.offsetParent) {
    return offsetTop(element.offsetParent, top);
  }
  return top;
}
function offsetLeft(element, accumulator = 0) {
  const left = accumulator + element.offsetLeft;
  if (element.offsetParent) {
    return offsetLeft(element.offsetParent, left);
  }
  return left;
}
function scrollTop(element, accumulator = 0) {
  const top = accumulator + element.scrollTop;
  if (element.offsetParent) {
    return scrollTop(element.offsetParent, top);
  }
  return top + window.scrollY;
}
function scrollLeft(element, accumulator = 0) {
  const left = accumulator + element.scrollLeft;
  if (element.offsetParent) {
    return scrollLeft(element.offsetParent, left);
  }
  return left + window.scrollX;
}
var SnapElement = class {
  element;
  options;
  align;
  // @ts-ignore
  rect = {};
  wrapperResizeObserver;
  resizeObserver;
  debouncedWrapperResize;
  constructor(element, {
    align = ["start"],
    ignoreSticky = true,
    ignoreTransform = false
  } = {}) {
    this.element = element;
    this.options = { align, ignoreSticky, ignoreTransform };
    this.align = [align].flat();
    this.debouncedWrapperResize = debounce(this.onWrapperResize, 500);
    this.wrapperResizeObserver = new ResizeObserver(this.debouncedWrapperResize);
    this.wrapperResizeObserver.observe(document.body);
    this.onWrapperResize();
    this.resizeObserver = new ResizeObserver(this.onResize);
    this.resizeObserver.observe(this.element);
    this.setRect({
      width: this.element.offsetWidth,
      height: this.element.offsetHeight
    });
  }
  destroy() {
    this.wrapperResizeObserver.disconnect();
    this.resizeObserver.disconnect();
  }
  setRect({
    top,
    left,
    width,
    height,
    element
  } = {}) {
    top = top ?? this.rect.top;
    left = left ?? this.rect.left;
    width = width ?? this.rect.width;
    height = height ?? this.rect.height;
    element = element ?? this.rect.element;
    if (top === this.rect.top && left === this.rect.left && width === this.rect.width && height === this.rect.height && element === this.rect.element)
      return;
    this.rect.top = top;
    this.rect.y = top;
    this.rect.width = width;
    this.rect.height = height;
    this.rect.left = left;
    this.rect.x = left;
    this.rect.bottom = top + height;
    this.rect.right = left + width;
  }
  onWrapperResize = () => {
    let top, left;
    if (this.options.ignoreSticky) removeParentSticky(this.element);
    if (this.options.ignoreTransform) {
      top = offsetTop(this.element);
      left = offsetLeft(this.element);
    } else {
      const rect = this.element.getBoundingClientRect();
      top = rect.top + scrollTop(this.element);
      left = rect.left + scrollLeft(this.element);
    }
    if (this.options.ignoreSticky) addParentSticky(this.element);
    this.setRect({ top, left });
  };
  onResize = ([entry]) => {
    if (!entry?.borderBoxSize[0]) return;
    const width = entry.borderBoxSize[0].inlineSize;
    const height = entry.borderBoxSize[0].blockSize;
    this.setRect({ width, height });
  };
};

// packages/snap/src/uid.ts
var index = 0;
function uid() {
  return index++;
}

// packages/snap/src/snap.ts
var Snap = class {
  constructor(lenis, {
    type = "proximity",
    lerp,
    easing,
    duration,
    distanceThreshold = "50%",
    // useless when type is "mandatory"
    debounce: debounceDelay = 500,
    onSnapStart,
    onSnapComplete
  } = {}) {
    this.lenis = lenis;
    this.options = {
      type,
      lerp,
      easing,
      duration,
      distanceThreshold,
      debounce: debounceDelay,
      onSnapStart,
      onSnapComplete
    };
    this.onWindowResize();
    window.addEventListener("resize", this.onWindowResize, false);
    this.onSnapDebounced = debounce(this.onSnap, this.options.debounce);
    this.lenis.on("virtual-scroll", this.onSnapDebounced);
  }
  options;
  elements = /* @__PURE__ */ new Map();
  snaps = /* @__PURE__ */ new Map();
  viewport = {
    width: window.innerWidth,
    height: window.innerHeight
  };
  isStopped = false;
  onSnapDebounced;
  currentSnapIndex;
  /**
   * Destroy the snap instance
   */
  destroy() {
    this.lenis.off("virtual-scroll", this.onSnapDebounced);
    window.removeEventListener("resize", this.onWindowResize, false);
    this.elements.forEach((element) => {
      element.destroy();
    });
  }
  /**
   * Start the snap after it has been stopped
   */
  start() {
    this.isStopped = false;
  }
  /**
   * Stop the snap
   */
  stop() {
    this.isStopped = true;
  }
  /**
   * Add a snap to the snap instance
   *
   * @param value The value to snap to
   * @param userData User data that will be forwarded through the snap event
   * @returns Unsubscribe function
   */
  add(value) {
    const id = uid();
    this.snaps.set(id, { value });
    return () => this.snaps.delete(id);
  }
  /**
   * Add an element to the snap instance
   *
   * @param element The element to add
   * @param options The options for the element
   * @returns Unsubscribe function
   */
  addElement(element, options = {}) {
    const id = uid();
    this.elements.set(id, new SnapElement(element, options));
    return () => this.elements.delete(id);
  }
  addElements(elements, options = {}) {
    const map = [...elements].map(
      (element) => this.addElement(element, options)
    );
    return () => {
      map.forEach((remove) => {
        remove();
      });
    };
  }
  onWindowResize = () => {
    this.viewport.width = window.innerWidth;
    this.viewport.height = window.innerHeight;
  };
  computeSnaps = () => {
    const { isHorizontal } = this.lenis;
    let snaps = [...this.snaps.values()];
    this.elements.forEach(({ rect, align }) => {
      let value;
      align.forEach((align2) => {
        if (align2 === "start") {
          value = rect.top;
        } else if (align2 === "center") {
          value = isHorizontal ? rect.left + rect.width / 2 - this.viewport.width / 2 : rect.top + rect.height / 2 - this.viewport.height / 2;
        } else if (align2 === "end") {
          value = isHorizontal ? rect.left + rect.width - this.viewport.width : rect.top + rect.height - this.viewport.height;
        }
        if (typeof value === "number") {
          snaps.push({ value: Math.ceil(value) });
        }
      });
    });
    snaps = snaps.sort((a, b) => Math.abs(a.value) - Math.abs(b.value));
    return snaps;
  };
  previous() {
    this.goTo((this.currentSnapIndex ?? 0) - 1);
  }
  next() {
    this.goTo((this.currentSnapIndex ?? 0) + 1);
  }
  goTo(index2) {
    const snaps = this.computeSnaps();
    if (snaps.length === 0) return;
    this.currentSnapIndex = Math.max(0, Math.min(index2, snaps.length - 1));
    const currentSnap = snaps[this.currentSnapIndex];
    if (currentSnap === void 0) return;
    this.lenis.scrollTo(currentSnap.value, {
      duration: this.options.duration,
      easing: this.options.easing,
      lerp: this.options.lerp,
      lock: this.options.type === "lock",
      userData: { initiator: "snap" },
      onStart: () => {
        this.options.onSnapStart?.({
          index: this.currentSnapIndex,
          ...currentSnap
        });
      },
      onComplete: () => {
        this.options.onSnapComplete?.({
          index: this.currentSnapIndex,
          ...currentSnap
        });
      }
    });
  }
  get distanceThreshold() {
    let distanceThreshold = Infinity;
    if (this.options.type === "mandatory") return Infinity;
    const { isHorizontal } = this.lenis;
    const axis = isHorizontal ? "width" : "height";
    if (typeof this.options.distanceThreshold === "string" && this.options.distanceThreshold.endsWith("%")) {
      distanceThreshold = Number(this.options.distanceThreshold.replace("%", "")) / 100 * this.viewport[axis];
    } else if (typeof this.options.distanceThreshold === "number") {
      distanceThreshold = this.options.distanceThreshold;
    } else {
      distanceThreshold = this.viewport[axis];
    }
    return distanceThreshold;
  }
  onSnap = (e) => {
    if (this.isStopped) return;
    if (e.event.type === "touchmove") return;
    if (this.options.type === "lock" && this.lenis.userData?.initiator === "snap")
      return;
    let { scroll, isHorizontal } = this.lenis;
    const delta = isHorizontal ? e.deltaX : e.deltaY;
    scroll = Math.ceil(this.lenis.scroll + delta);
    const snaps = this.computeSnaps();
    if (snaps.length === 0) return;
    let snapIndex;
    const prevSnapIndex = snaps.findLastIndex(({ value }) => value < scroll);
    const nextSnapIndex = snaps.findIndex(({ value }) => value > scroll);
    if (this.options.type === "lock") {
      if (delta > 0) {
        snapIndex = nextSnapIndex;
      } else if (delta < 0) {
        snapIndex = prevSnapIndex;
      }
    } else {
      const prevSnap = snaps[prevSnapIndex];
      const distanceToPrevSnap = prevSnap ? Math.abs(scroll - prevSnap.value) : Infinity;
      const nextSnap = snaps[nextSnapIndex];
      const distanceToNextSnap = nextSnap ? Math.abs(scroll - nextSnap.value) : Infinity;
      snapIndex = distanceToPrevSnap < distanceToNextSnap ? prevSnapIndex : nextSnapIndex;
    }
    if (snapIndex === void 0) return;
    if (snapIndex === -1) return;
    snapIndex = Math.max(0, Math.min(snapIndex, snaps.length - 1));
    const snap = snaps[snapIndex];
    const distance = Math.abs(scroll - snap.value);
    if (distance <= this.distanceThreshold) {
      this.goTo(snapIndex);
    }
  };
  resize() {
    this.elements.forEach((element) => element.onWrapperResize());
  }
};

// packages/snap/browser.ts
globalThis.Snap = Snap;
//# sourceMappingURL=lenis-snap.js.map