"use client";

import { useEffect, useRef, type ReactNode } from "react";

type ParallaxProps = {
  children: ReactNode;
  className?: string;
  // How far the element drifts (px) as it travels across the viewport.
  // ~10-20 keeps it felt-but-subtle; the drift opposes the scroll.
  distance?: number;
};

/**
 * Subtle scroll parallax: translates the child a few pixels as it moves
 * through the viewport (center = neutral, edges = ±distance/2). Transform
 * only, rAF-throttled, passive listener — cheap on the main thread, and a
 * no-op for prefers-reduced-motion users.
 */
export default function Parallax({ children, className = "", distance = 16 }: ParallaxProps) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

    let raf = 0;
    const update = () => {
      raf = 0;
      const rect = el.getBoundingClientRect();
      const vh = window.innerHeight || 1;
      // -0.5 .. 0.5 as the element travels bottom -> top of the viewport.
      const progress = (rect.top + rect.height / 2) / vh - 0.5;
      el.style.transform = `translateY(${(-progress * distance).toFixed(1)}px)`;
    };
    const onScroll = () => {
      if (!raf) raf = requestAnimationFrame(update);
    };

    update();
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);
    return () => {
      window.removeEventListener("scroll", onScroll);
      window.removeEventListener("resize", onScroll);
      if (raf) cancelAnimationFrame(raf);
    };
  }, [distance]);

  return (
    <div ref={ref} className={className}>
      {children}
    </div>
  );
}
