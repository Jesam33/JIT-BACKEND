import Link from "next/link";
import Reveal from "./Reveal";

const secondaryCtas = [
  { href: "/institute", label: "Train with us" },
  { href: "/signup", label: "Create your online academy" },
];

/**
 * Editorial landing hero — static two-line Seraphine headline on black
 * with fine grid lines. Primary CTA is a red pill; the other two
 * destinations are hairline index rows beneath it.
 */
export default function EditorialHero() {
  return (
    <section className="hero-grid relative overflow-hidden">
      <div className="container-wide relative pb-20 pt-16 md:pb-28 md:pt-32">
        {/* Headline */}
        <Reveal delay={120}>
          <h1 className="font-editorial mt-8 max-w-6xl text-[clamp(2.9rem,9vw,7.5rem)] leading-[1.02] tracking-[-0.01em]">
            Born in Africa.
            <br />
            <span className="text-site-primary">Built for the world.</span>
          </h1>
        </Reveal>

        <div className="mt-10 flex flex-col gap-10 md:mt-14 lg:flex-row lg:items-end lg:justify-between">
          <Reveal delay={240} className="max-w-xl">
            <p className="text-base leading-8 text-site-text/75 md:text-lg">
              We design and develop powerful websites, mobile apps and digital
              solutions that help businesses grow, innovate and succeed, from
              Nigerian foundations to global standards.
            </p>
          </Reveal>

          {/* CTAs — white primary pill + hairline index for the rest */}
          <Reveal delay={360} className="lg:max-w-md lg:flex-1">
            <Link
              href="/qoute"
              className="group inline-flex items-center gap-3 rounded-full bg-site-primary px-8 py-4 text-sm font-semibold text-[#fff] shadow-lg shadow-site-primary/25 transition hover:shadow-xl hover:shadow-site-primary/35 hover:brightness-110 font-editorial"
            >
              Start a project
              <span
                aria-hidden="true"
                className="transition-transform duration-300 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
              >
                ↗
              </span>
            </Link>

            <div className="mt-6 divide-y divide-site-border/15 border-y border-site-border/20">
              {secondaryCtas.map((cta) => (
                <Link
                  key={cta.href}
                  href={cta.href}
                  className="group flex items-center justify-between gap-4 py-4"
                >
                  <span className="flex items-center gap-4">
                    <span
                      aria-hidden="true"
                      className="h-1.5 w-1.5 rounded-full bg-site-primary transition-transform duration-300 group-hover:scale-[1.8]"
                    />
                    <span className="font-editorial text-xl leading-none transition-transform duration-500 ease-out group-hover:translate-x-2 md:text-2xl">
                      {cta.label}
                    </span>
                  </span>
                  <span
                    aria-hidden="true"
                    className="text-lg text-site-text/40 transition-all duration-300 group-hover:-translate-y-0.5 group-hover:translate-x-1 group-hover:text-site-primary"
                  >
                    ↗
                  </span>
                </Link>
              ))}
            </div>
          </Reveal>
        </div>
      </div>
    </section>
  );
}
