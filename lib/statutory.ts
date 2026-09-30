import "server-only";
import { prisma } from "./prisma";

type PtSlab = { upto: number | null; amount: number };
type TdsSlab = { upto: number | null; monthlyDeduction: number };

/** Returns the company's statutory config, creating BRD-standard Indian defaults on first use. */
export async function getStatutoryConfig(companyId: string) {
  const existing = await prisma.statutoryConfig.findUnique({ where: { companyId } });
  if (existing) return existing;

  return prisma.statutoryConfig.create({
    data: {
      companyId,
      pfEmployeeRate: 0.12,
      pfEmployerRate: 0.12,
      pfWageCeiling: 15000,
      esiEmployeeRate: 0.0075,
      esiEmployerRate: 0.0325,
      esiWageCeiling: 21000,
      ptSlabs: [
        { upto: 15000, amount: 0 },
        { upto: 25000, amount: 150 },
        { upto: null, amount: 200 },
      ] satisfies PtSlab[],
      tdsSlabs: [
        { upto: 600000, monthlyDeduction: 0 },
        { upto: 1200000, monthlyDeduction: 2500 },
        { upto: null, monthlyDeduction: 8000 },
      ] satisfies TdsSlab[],
    },
  });
}

function slabLookup(annualOrMonthly: number, slabs: unknown): number {
  const rows = (Array.isArray(slabs) ? slabs : []) as { upto: number | null; amount?: number; monthlyDeduction?: number }[];
  for (const row of rows) {
    if (row.upto === null || annualOrMonthly <= row.upto) {
      return row.amount ?? row.monthlyDeduction ?? 0;
    }
  }
  return 0;
}

/**
 * Computes PF/ESI/PT/TDS from `basic` using the company's configurable rates
 * (BRD FR-061 — "using configurable, regulation-updatable rules" rather than
 * numbers typed in by hand per employee). ESI applies only below its wage
 * ceiling, matching how ESI eligibility actually works.
 */
export async function computeStatutoryDeductions(companyId: string, basic: number) {
  const cfg = await getStatutoryConfig(companyId);

  const pfWage = Math.min(basic, cfg.pfWageCeiling);
  const pfEmployee = Math.round(pfWage * cfg.pfEmployeeRate);

  const esi = basic <= cfg.esiWageCeiling ? Math.round(basic * cfg.esiEmployeeRate) : 0;

  const professionalTax = slabLookup(basic, cfg.ptSlabs);
  const tds = slabLookup(basic * 12, cfg.tdsSlabs);

  return { pfEmployee, esi, professionalTax, tds };
}
