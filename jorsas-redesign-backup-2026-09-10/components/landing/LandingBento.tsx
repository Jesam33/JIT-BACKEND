import Image from "next/image";
import Link from "next/link";
import Reveal from "./Reveal";

type TileProps = {
  src: string;
  alt: string;
  label: string;
  className?: string;
  delay?: number;
  variant?: "fade" | "left" | "right" | "scale";
};

/** One bento image tile: photo on a fine border, slow zoom on hover,
 *  small frosted caption chip bottom-left. */
function BentoImage({ src, alt, label, className = "", delay = 0, variant = "fade" }: TileProps) {
  return (
    <Reveal delay={delay} variant={variant} className={className}>
      <div className="group relative h-full w-full overflow-hidden rounded-xl border border-site-border/20">
        <Image
          src={src}
          alt={alt}
          fill
          sizes="(max-width: 768px) 92vw, 50vw"
          className="object-cover transition duration-700 ease-out group-hover:scale-[1.06]"
        />
        <span className="absolute bottom-3 left-3 rounded-full bg-black/45 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-[#fff] backdrop-blur-sm">
          {label}
        </span>
      </div>
    </Reveal>
  );
}

/**
 * What we do, as a bento grid built from the three studio code shots —
 * one large anchor tile, a red statement tile, and two standard tiles.
 */
export default function LandingBento() {
  return (
    <section className="section-pad">
      <div className="container-wide">
        {/* Section header */}
        <Reveal>
          <div className="mb-12 md:mb-16">
            <h2 className="font-editorial text-4xl leading-[1.05] md:text-6xl">
              What we do
            </h2>
          </div>
        </Reveal>

        {/* Bento grid */}
        <div className="grid auto-rows-[150px] grid-cols-2 gap-4 md:auto-rows-[215px] lg:grid-cols-4">
          {/* Anchor tile */}
          <BentoImage
            src="/images/sections/code-3.png"
            alt="Jorsas engineering at work"
            label="Engineering"
            className="col-span-2 row-span-2"
            variant="scale"
          />

          {/* Red statement tile */}
          <Reveal delay={100} className="col-span-2">
            <div className="flex h-full w-full flex-col justify-between rounded-xl bg-site-primary p-6 md:p-8">
              <p className="text-[10px] font-semibold uppercase tracking-[0.25em] text-[#fff]/70">
                Full-stack studio
              </p>
              <div>
                <h3 className="font-editorial text-2xl leading-tight text-[#fff] md:text-3xl">
                  One team, end to end.
                </h3>
                <Link
                  href="/services"
                  className="group mt-4 inline-flex items-center gap-2 text-sm font-semibold text-[#fff] transition hover:text-[#fff]/85"
                >
                  <span className="border-b border-[#fff]/40 pb-0.5 transition group-hover:border-[#fff]">
                    All services
                  </span>
                  <span aria-hidden="true" className="transition group-hover:translate-x-1">→</span>
                </Link>
              </div>
            </div>
          </Reveal>

          <BentoImage
            src="/images/sections/code-2.png"
            alt="A Jorsas delivery"
            label="Our work"
            delay={150}
            variant="right"
          />
          <BentoImage
            src="/images/sections/code-4.png"
            alt="Web development at Jorsas"
            label="Web development"
            delay={200}
            variant="right"
          />
        </div>
      </div>
    </section>
  );
}
