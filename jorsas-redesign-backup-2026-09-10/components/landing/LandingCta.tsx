import Link from "next/link";
import Reveal from "./Reveal";

/**
 * Closing CTA — big Maroes invitation over a red glow, with every
 * conversion the landing carries: request a quote, train with us, and
 * the academy signup.
 */
export default function LandingCta() {
  return (
    <section className="section-pad relative overflow-hidden border-t border-site-border/15">
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -bottom-48 left-1/2 h-[560px] w-[900px] -translate-x-1/2 rounded-full bg-site-primary/15 blur-[160px]"
      />
      <div className="container-wide relative text-center">
        <Reveal variant="scale">
          <h2 className="font-editorial mx-auto max-w-4xl text-[clamp(2.6rem,7vw,5.5rem)] leading-[1.05]">
            Let&apos;s build <span className="text-site-primary">for you.</span>
          </h2>
          <p className="mx-auto mt-8 max-w-xl text-base leading-8 text-site-text/70">
            Tell us about your project or your academy and we&apos;ll come
            back with a plan. No jargon, no surprises.
          </p>
          <div className="mt-10 flex flex-wrap items-center justify-center gap-4">
            <Link
              href="/qoute"
              className="font-editorial inline-flex items-center gap-3 rounded-full bg-site-primary px-8 py-4 text-xs font-semibold uppercase tracking-[0.18em] text-[#fff] shadow-lg shadow-site-primary/25 transition hover:shadow-xl hover:shadow-site-primary/35 hover:brightness-110"
            >
              Request a quote
              <span aria-hidden="true">↗</span>
            </Link>
            <Link
              href="/institute"
              className="font-editorial inline-flex items-center gap-3 rounded-full border border-site-border/50 px-8 py-4 text-xs font-semibold uppercase tracking-[0.18em] text-site-text transition hover:border-site-text/70 hover:bg-site-text/5"
            >
              Train with us
            </Link>
          </div>
          <Link
            href="/signup"
            className="group mt-8 inline-flex items-center gap-2 text-sm text-site-text/60 transition hover:text-site-text"
          >
            <span className="border-b border-site-border/50 pb-0.5 transition group-hover:border-site-primary group-hover:text-site-primary">
              Or create your online academy in minutes
            </span>
            <span aria-hidden="true" className="transition group-hover:translate-x-1">→</span>
          </Link>
        </Reveal>
      </div>
    </section>
  );
}
