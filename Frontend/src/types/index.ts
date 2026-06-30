export interface User {
  id: number;
  name: string;
  email: string;
  role: string;
  system_role: string;
  tenant_id: number;
  job_role_id?: number;
  avatar?: string;
  salary?: number;
  base_salary?: number;
  shiftStart?: string;
  shiftEnd?: string;
  mealMinutes?: number;
  restDay?: string;
  portadorLlaves?: string;
  employee_id?: string;
  phone?: string;
  pin_code?: string;
  has_completed_induction?: boolean;
  hire_date?: string;
  tenant?: Tenant;
}

export interface Tenant {
  id: number;
  name: string;
  subdomain: string;
  plan: 'freemium' | 'pro' | 'enterprise';
  is_active: boolean;
  public_slug?: string;
  brand_color?: string;
  logo_url?: string;
  public_portal_enabled?: boolean;
  subscription_status?: string;
  trial_ends_at?: string;
  created_at?: string;
}

export interface AppModule {
  id: string;
  title: string;
  desc: string;
  icon: React.ReactNode;
  color: string;
  minTier: 'freemium' | 'pro' | 'enterprise';
  version: string;
}
