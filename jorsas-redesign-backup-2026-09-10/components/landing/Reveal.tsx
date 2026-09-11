"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";

// Motion vocabulary: fade rises up, left/right slide in from a side
// (split layouts), scale settles from a slight zoom (images).
const variantClass = {
  fade: "reveal-fade",
  left: "reveal-left",
  right: "reveal-right",
  scale: "reveal-scale",
} as const;

type RevealProps = {
  children: ReactNode;
  className?: string;
  // Stagger delay in ms — transitionDelay on the wrapper.
  delay?: number;
  variant?: keyof typeof variantClass;
};

/**
 * Scroll-reveal wrapper for the landing page. The child content starts
 * hidden and settles into place once when it enters the viewport.
 * Users with prefers-reduced-motion see content immediately (CSS also
 * disables the animation as a second guard).
 */
export default function Reveal({ children, className = "", delay = 0, variant = "fade" }: RevealProps) {
  const ref = useRef<HTMLDivElement>(null);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      setVisible(true);
      return;
    }
    const io = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setVisible(true);
          io.disconnect();
        }
      },
      { threshold: 0.15, rootMargin: "0px 0px -8% 0px" }
    );
    io.observe(el);
    return () => io.disconnect();
  }, []);

  return (
    <div
      ref={ref}
      className={`${variantClass[variant]} ${visible ? "is-visible" : ""} ${className}`}
      style={delay ? { transitionDelay: `${delay}ms` } : undefined}
    >
      {children}
    </div>
  );
}
