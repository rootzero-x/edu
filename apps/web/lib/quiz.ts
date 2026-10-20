/** Topshirishdan oldin bo'sh javoblarni olib tashlaydi (bo'sh matn serverda null bo'lib 422 berardi). */
export function cleanAnswers(answers: Record<string | number, string[]>): Record<string, string[]> {
  const out: Record<string, string[]> = {};
  for (const [k, v] of Object.entries(answers)) {
    const vals = (v ?? []).filter((x) => typeof x === "string" && x.trim() !== "");
    if (vals.length) out[k] = vals;
  }
  return out;
}
