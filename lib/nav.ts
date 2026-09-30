import type { Role } from "@prisma/client";
import {
  LayoutDashboard,
  Building2,
  Users,
  Fingerprint,
  CalendarDays,
  Wallet,
  Briefcase,
  Target,
  Receipt,
  FolderOpen,
  Boxes,
  LifeBuoy,
  BarChart3,
  Bell,
  Settings,
  History,
  ShieldCheck,
  ClipboardCheck,
  LogOut,
  type LucideIcon,
} from "lucide-react";

const ALL_ROLES: Role[] = [
  "SUPER_ADMIN",
  "COMPANY_ADMIN",
  "HR",
  "MANAGER",
  "EMPLOYEE",
  "RECRUITER",
  "PAYROLL_ADMIN",
  "FINANCE",
  "IT_ADMIN",
  "AUDITOR",
  "MANAGEMENT",
];

export type NavItem = {
  label: string;
  href: string;
  icon: LucideIcon;
  roles: Role[];
};

export const NAV_ITEMS: NavItem[] = [
  {
    label: "Dashboard",
    href: "/dashboard",
    icon: LayoutDashboard,
    roles: ALL_ROLES,
  },
  {
    label: "Companies",
    href: "/dashboard/companies",
    icon: Building2,
    roles: ["SUPER_ADMIN"],
  },
  {
    label: "Users",
    href: "/dashboard/users",
    icon: Users,
    roles: ["SUPER_ADMIN"],
  },
  {
    label: "Audit Logs",
    href: "/dashboard/audit",
    icon: History,
    roles: ["SUPER_ADMIN", "AUDITOR", "COMPANY_ADMIN"],
  },
  {
    label: "Roles & Permissions",
    href: "/dashboard/roles",
    icon: ShieldCheck,
    roles: ["SUPER_ADMIN"],
  },
  {
    label: "Employees",
    href: "/dashboard/employees",
    icon: Users,
    roles: ["COMPANY_ADMIN", "HR", "MANAGER"],
  },
  {
    label: "Attendance",
    href: "/dashboard/attendance",
    icon: Fingerprint,
    roles: ["COMPANY_ADMIN", "HR", "MANAGER", "EMPLOYEE"],
  },
  {
    label: "Leave",
    href: "/dashboard/leave",
    icon: CalendarDays,
    roles: ["COMPANY_ADMIN", "HR", "MANAGER", "EMPLOYEE"],
  },
  {
    label: "Payroll",
    href: "/dashboard/payroll",
    icon: Wallet,
    roles: ["COMPANY_ADMIN", "HR", "MANAGER", "EMPLOYEE", "PAYROLL_ADMIN", "FINANCE"],
  },
  {
    label: "Recruitment",
    href: "/dashboard/recruitment",
    icon: Briefcase,
    roles: ["COMPANY_ADMIN", "HR", "RECRUITER"],
  },
  {
    label: "Onboarding",
    href: "/dashboard/onboarding",
    icon: ClipboardCheck,
    roles: ["COMPANY_ADMIN", "HR", "IT_ADMIN", "MANAGER"],
  },
  {
    label: "Performance",
    href: "/dashboard/performance",
    icon: Target,
    roles: ["COMPANY_ADMIN", "HR", "MANAGER", "EMPLOYEE"],
  },
  {
    label: "Expenses",
    href: "/dashboard/expenses",
    icon: Receipt,
    roles: ["COMPANY_ADMIN", "HR", "MANAGER", "EMPLOYEE", "FINANCE"],
  },
  {
    label: "Documents",
    href: "/dashboard/documents",
    icon: FolderOpen,
    roles: ["COMPANY_ADMIN", "HR", "MANAGER", "EMPLOYEE"],
  },
  {
    label: "Assets",
    href: "/dashboard/assets",
    icon: Boxes,
    roles: ["COMPANY_ADMIN", "HR", "MANAGER", "EMPLOYEE", "IT_ADMIN"],
  },
  {
    label: "Helpdesk",
    href: "/dashboard/helpdesk",
    icon: LifeBuoy,
    roles: ["COMPANY_ADMIN", "HR", "MANAGER", "EMPLOYEE", "IT_ADMIN", "FINANCE"],
  },
  {
    label: "Exit / Offboarding",
    href: "/dashboard/exit",
    icon: LogOut,
    roles: ["COMPANY_ADMIN", "HR", "MANAGER", "IT_ADMIN", "FINANCE"],
  },
  {
    label: "Reports",
    href: "/dashboard/reports",
    icon: BarChart3,
    roles: ["COMPANY_ADMIN", "HR", "MANAGER", "AUDITOR", "MANAGEMENT", "PAYROLL_ADMIN", "RECRUITER"],
  },
  {
    label: "Settings",
    href: "/dashboard/settings",
    icon: Settings,
    roles: ["COMPANY_ADMIN"],
  },
];

export const BELL_ICON = Bell;

export function navForRole(role: Role) {
  return NAV_ITEMS.filter((item) => item.roles.includes(role));
}

