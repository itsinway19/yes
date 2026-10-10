export type GoalValue = { goals: string[]; priority: string | null; results: Record<string, string[]> };
export type GoalCatalog = { id: string; name_ar: string; name_en: string; description_ar: string; description_en: string; results: { id: string; name_ar: string; name_en: string }[] }[];
export const cloneGoals = (value: GoalValue): GoalValue => JSON.parse(JSON.stringify(value));
// PostgreSQL jsonb can reorder object keys. Compare the same explicit shape,
// treating selected IDs as sets without changing the displayed/submitted draft.
export const goalSignature = (value: GoalValue): string => JSON.stringify({
    goals: [...value.goals].sort(), priority: value.priority,
    results: Object.fromEntries([...value.goals].sort().map(goal => [goal, [...(value.results[goal] ?? [])].sort()])),
});
export function toggleGoal(value: GoalValue, id: string): void {
    if (value.goals.includes(id)) {
        value.goals = value.goals.filter(goal => goal !== id);
        delete value.results[id];
        if (value.goals.length === 1) value.priority = value.goals[0];
        else if (value.priority === id || value.goals.length === 0) value.priority = null;
    } else {
        value.goals.push(id);
        if (value.goals.length === 1) value.priority = id;
    }
}
export function setPriority(value: GoalValue, id: string): void {
    if (value.goals.includes(id)) value.priority = id;
}
export function toggleResult(value: GoalValue, goal: string, id: string): void {
    if (!value.goals.includes(goal)) return;
    const selected = value.results[goal] ?? [];
    value.results[goal] = selected.includes(id) ? selected.filter(result => result !== id) : [...selected, id];
}
export const canContinue = (value: GoalValue): boolean => value.goals.length > 0 && value.priority !== null && value.goals.includes(value.priority);
