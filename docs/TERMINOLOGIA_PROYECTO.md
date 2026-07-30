# Terminología Oficial y Glosario de Entornos — Talent360

Este documento define la nomenclatura estandarizada para la comunicación operativa, requerimientos y desarrollo dentro de la plataforma **Talent360**.

---

## 📋 Mapeo General de Secciones y Entornos

| Término Oficial | Entorno Técnico | Ruta / URL | Componente Principal | Responsabilidad y Alcance |
| :--- | :--- | :--- | :--- | :--- |
| **"La Plataforma"** | Panel SuperAdmin | `/superadmin` | `SaaSPlatformAdmin.tsx` | Consola de administración global del SaaS: gestión de empresas (tenants), control de licencias, analítica financiera (MRR/ARR), auditorías de sistema y configuraciones globales. |
| **"La Empresa"** *(o nombre de la empresa)* | Tenant / Dashboard de Empresa | `/app` | `DashboardTalent360.tsx`, `App.tsx` | Entorno operativo cliente donde habitan los 12 Mega-Módulos (RH, Reloj, Tareas IA, ATS, Academia, Documentos, Nómina, LFT, Organigrama, Matrix). |
| **"Área de Soporte"** | Panel de Soporte Técnico | `/soporte` | `SaaSPlatformAdmin.tsx` | Superficie de atención a clientes y resolución de tickets de soporte técnico. |
| **"La Página"** | Portal Público Comercial | `/inicio` | `SaaSLandingPage.tsx` | Sitio público de mercadotecnia, información comercial, tabla de planes y onboarding de registro de nuevas empresas. |

---

## 🛠️ Los 12 Mega-Módulos de "La Empresa" (`/app`)

1. **Monitor 360** (`dashboard`): Supervisión en tiempo real.
2. **Directorio Digital / RH** (`rrhh`): Estructura organizacional, colaboradores y expediente.
3. **Reloj Checador** (`reloj`): Asistencia, turnos, geolocalización y Ley Silla.
4. **Tareas IA** (`operativo`): Asignación y automatización de rutinas.
5. **Reportes IA** (`reportes`): Analítica de nómina e incidencias.
6. **Bolsa de Trabajo ATS** (`ats`): Gestión de vacantes y candidatos.
7. **Academia 360** (`academia`): Inducción, capacitaciones y certificados QR.
8. **Archivo Digital** (`documentos`): Expedientes y visor PDF.
9. **Nómina CFDI 4.0** (`facturacion`): Timbrado masivo SAT.
10. **Ley Federal del Trabajo** (`lft`): Calculadora de finiquitos y reportes STPS.
11. **Organigrama y SOP** (`organizacion`): Puestos, jerarquías y wiki de procesos.
12. **Matrix QA** (`matrix`): Entorno de simulación de pruebas.

---

*Última actualización: 2026-07-29*
