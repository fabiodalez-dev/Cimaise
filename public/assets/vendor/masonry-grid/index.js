function B(p, r, e) {
  if (p.length > 0) {
    if (p[0].target === r)
      return p[0].contentRect.width;
    if (p.length === 2 && p[1].target === r)
      return p[1].contentRect.width;
  }
  return e;
}
class x {
  constructor(r) {
    this.container = r;
    const e = getComputedStyle(r), a = document.createElement("div");
    r.append(a);
    const l = new ResizeObserver((s) => {
      const t = B(
        s,
        r,
        this.containerWidth
      ), m = B(s, a, this.frameWidth);
      let i = this.gap;
      if ((i === -1 || m !== this.frameWidth) && (i = parseFloat(e.gap), isNaN(i) && (i = 0)), i === this.gap && t === this.containerWidth && m === this.frameWidth)
        return;
      this.containerWidth = t, this.frameWidth = m;
      const f = Math.round(
        (t + i) / (m + i)
      );
      if (this.columnsCount === f && i === this.gap) {
        n(), this.resizeHeight(), o();
        return;
      }
      this.gap = i, this.columnsCount = f, n(), this.reflow(), o();
    }), h = new MutationObserver(() => {
      n(), r.append(a), this.columnsCount > 0 && this.reflow(!0), o();
    }), o = () => {
      h.observe(r, {
        childList: !0,
        attributeFilter: ["style"],
        subtree: !0
      });
    }, n = () => {
      h.disconnect();
    };
    this.marker = a, this.mutationObserver = h, this.resizeObserver = l, l.observe(r), l.observe(a), o();
  }
  /**
   * Grid gap value in pixels.
   * -1 means that the gap is not calculated or set.
   */
  gap = -1;
  /**
   * Width of the frame in pixels.
   * -1 means that the width is not calculated or set.
   */
  frameWidth = -1;
  /**
   * Width of the container in pixels.
   * -1 means that the width is not calculated or set.
   */
  containerWidth = -1;
  /**
   * Number of columns in the grid.
   * -1 means that the number of columns is not calculated or set.
   */
  columnsCount = -1;
  /**
   * Aspect ratio of the container.
   * -1 means that the aspect ratio is not calculated or set.
   */
  containerAspectRatio = -1;
  /**
   * Map of frames positions.
   * Key is the frame element, value is the FramePosition object.
   */
  framesPositionsMap = /* @__PURE__ */ new WeakMap();
  /**
   * Resize observer to observe changes in the container and marker size.
   */
  resizeObserver;
  /**
   * Mutation observer to observe changes in the container's children.
   */
  mutationObserver;
  /**
   * Marker element to observe column width changes.
   */
  marker;
  /**
   * Resize the height of the container based on the current width and aspect ratio.
   * If the aspect ratio is not set, the height will be removed.
   * Aspect ratio should be set while reflowing the grid.
   */
  resizeHeight() {
    const {
      container: r,
      containerAspectRatio: e
    } = this;
    e !== -1 ? r.style.height = `${this.containerWidth * e}px` : r.style.removeProperty("height");
  }
  /**
   * Get the aspect ratio of the frame based on its width and height.
   * @param element - The frame element to calculate the aspect ratio for.
   * @returns The aspect ratio of the frame as a number (height / width).
   */
  getFrameAspectRatio(r) {
    const e = parseFloat(r.style.getPropertyValue("--width"));
    return parseFloat(r.style.getPropertyValue("--height")) / e;
  }
  /**
   * Get the position of the frame in the grid.
   * @param element - The frame element to get the position for.
   * @param i - The real index of the frame in the grid.
   * @param offset - The offset from the top of the container in pixels.
   * @returns An object containing the position of the frame in the grid.
   */
  getFramePosition(r, e, a) {
    const {
      gap: l,
      columnsCount: h,
      frameWidth: o
    } = this, n = this.getFrameAspectRatio(r), s = n * o, t = a + s + (e >= h ? l : 0);
    return {
      aspectRatio: n,
      realIndex: e,
      virtualIndex: e,
      height: s,
      realBottom: t,
      virtualBottom: t,
      width: o
    };
  }
  /**
   * Get the position of the frame in the grid and cache it.
   * @param element - The frame element to get the position for.
   * @param i - The real index of the frame in the grid.
   * @param offset - The offset from the top of the container in pixels.
   * @returns An object containing the position of the frame in the grid.
   */
  getFramePositionAndCache(r, e, a) {
    const l = this.getFramePosition(r, e, a);
    return this.framesPositionsMap.set(r, l), l;
  }
  /**
   * Get the cached position of the frame in the grid and scale it to the current frame width.
   * If the frame position is not cached or the index does not match, return null.
   * @param element - The frame element to get the position for.
   * @param i - The real index of the frame in the grid.
   * @returns An object containing the scaled position of the frame in the grid or null if not found.
   */
  getCachedScaledFramePosition(r, e, a) {
    const { frameWidth: l } = this, h = this.getFrameAspectRatio(r), o = this.framesPositionsMap.get(r);
    if (!o || o.realIndex !== e || o.virtualIndex >= a || o.aspectRatio !== h)
      return null;
    const n = l / o.width;
    return o.height *= n, o.realBottom *= n, o.virtualBottom *= n, o.width = l, o;
  }
  /**
   * Destroy the MasonryGrid instance.
   */
  destroy() {
    const {
      resizeObserver: r,
      mutationObserver: e,
      marker: a,
      container: l,
      framesPositionsMap: h
    } = this;
    r.disconnect(), e.disconnect(), a.remove();
    const o = l.children;
    l.style.removeProperty("height");
    for (let n = 0, s, t = o.length; n < t; n++)
      s = o[n], s.style.removeProperty("transform"), s.style.removeProperty("order"), h.delete(s);
  }
}
class M extends x {
  bufferA = [];
  bufferB = [];
  /**
   * Balance row with previous.
   * Current implementation stacks row with previous to have minimal possible height.
   * @param positions - Virtual positions of the frames in the grid.
   * @param start - Start index of the row to balance.
   * @param end - End index of the row to balance.
   */
  balanceRow(r, e, a) {
    const {
      columnsCount: l,
      bufferA: h,
      bufferB: o
    } = this, n = a - e + 1;
    h.length = n, o.length = n;
    for (let s = 0, t = e; t <= a; s++, t++)
      h[s] = r[t - l], o[s] = r[t];
    h.sort((s, t) => t.virtualBottom - s.virtualBottom), o.sort((s, t) => s.height - t.height);
    for (let s = 0, t = e, m = 0; t <= a; s++, t++)
      m = h[s].virtualIndex + l, r[m] = o[s], o[s].virtualIndex = m;
  }
  reflow(r = !1) {
    const { columnsCount: e } = this;
    if (r && e === 1)
      return;
    const {
      container: a,
      marker: l
    } = this, h = a.children, o = h.length - 1, n = o - 1, s = Array(o);
    let t = -1, m = r;
    l.style.order = String(o);
    for (let i = 0, f, u = 0, g = 0, c; i < o; i++) {
      if (f = h[i], e === 1) {
        f.style.removeProperty("transform"), f.style.removeProperty("order");
        continue;
      }
      if (i % e === 0 && (g = u), m) {
        const v = this.getCachedScaledFramePosition(
          f,
          i,
          o
        );
        if (v)
          c = v, s[c.virtualIndex] = c;
        else {
          m = !1;
          const y = i - i % e;
          for (let d = y, P; d < i; d++)
            P = h[d], c = this.getFramePositionAndCache(
              P,
              d,
              g
            ), s[d] = c;
        }
      }
      if (m)
        u = Math.max(
          u,
          c.realBottom
        ), t = Math.max(t, c.virtualBottom);
      else if (c = this.getFramePositionAndCache(f, i, g), s[i] = c, i >= e && ((i + 1) % e === 0 || i === n)) {
        const v = i - i % e, y = i;
        this.balanceRow(s, v, y);
        for (let d = v; d <= y; d++) {
          c = s[d], f = h[c.realIndex], f.style.order = String(c.virtualIndex);
          const P = s[d - e], b = g - P.virtualBottom;
          if (b !== 0) {
            const R = b * 100 / c.height * -1;
            f.style.transform = `translateY(${R}%)`, c.virtualBottom -= b;
          } else
            f.style.removeProperty("transform");
          u = Math.max(
            u,
            c.realBottom
          ), t = Math.max(
            t,
            c.virtualBottom
          );
        }
      } else i < e && (f.style.order = String(i), f.style.removeProperty("transform"), f.style.removeProperty("order"), u = Math.max(
        u,
        c.realBottom
      ), t = Math.max(t, c.virtualBottom));
    }
    t === -1 ? (a.style.removeProperty("height"), this.containerAspectRatio = -1) : (a.style.height = `${t}px`, this.containerAspectRatio = t / this.containerWidth);
  }
  destroy() {
    super.destroy(), this.bufferA.length = 0, this.bufferB.length = 0;
  }
}
class C extends x {
  reflow(r = !1) {
    const { columnsCount: e } = this;
    if (r && e === 1)
      return;
    const {
      container: a,
      framesPositionsMap: l
    } = this, h = a.children, o = h.length - 1;
    let n = -1, s = r;
    for (let t = 0, m, i = 0, f = 0, u; t < o; t++) {
      if (m = h[t], e === 1) {
        m.style.removeProperty("transform");
        continue;
      }
      if (t % e === 0 && (f = i), s) {
        const g = this.getCachedScaledFramePosition(
          m,
          t,
          o
        );
        g ? u = g : s = !1;
      }
      if (!s)
        if (u = this.getFramePositionAndCache(m, t, f), t >= e) {
          const g = l.get(
            h[t - e]
          ), c = f - g.virtualBottom;
          if (c !== 0) {
            const v = c * 100 / u.height * -1;
            m.style.transform = `translateY(${v}%)`, u.virtualBottom -= c;
          } else
            m.style.removeProperty("transform");
        } else
          m.style.removeProperty("transform");
      i = Math.max(i, u.realBottom), n = Math.max(n, u.virtualBottom);
    }
    n === -1 ? (a.style.removeProperty("height"), this.containerAspectRatio = -1) : (a.style.height = `${n}px`, this.containerAspectRatio = n / this.containerWidth);
  }
}
const w = C;
export {
  M as BalancedMasonryGrid,
  x as BaseMasonryGrid,
  w as MasonryGrid,
  C as RegularMasonryGrid
};
//# sourceMappingURL=index.js.map
