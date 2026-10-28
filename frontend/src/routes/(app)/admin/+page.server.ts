import { error } from "@sveltejs/kit";
import type { AdminDashboard } from "#lib/api/admin.ts";
export async function load({ fetch }) { const response = await fetch("/api/admin/dashboard", { headers: { Accept: "application/json" } }); if (!response.ok) error(response.status, "Administration data could not be loaded."); return { dashboard: (await response.json() as { data: AdminDashboard }).data }; }
