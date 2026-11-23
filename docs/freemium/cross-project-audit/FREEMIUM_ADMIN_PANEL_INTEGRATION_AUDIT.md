# Freemium Admin Panel Integration Audit

**Document Reference:** `docs/freemium/cross-project-audit/FREEMIUM_ADMIN_PANEL_INTEGRATION_AUDIT.md`  
**Execution Date:** 2026-11-20  
**Target Repository:** `E:\Al-Faraabi Software\tst-admin` (`admin_thestudentstimes.git`)  
**Secondary Inspection:** `E:\Al-Faraabi Software\3peas_admin` (`3peas_admin.git` - Legacy CoreUI template)  
**Status:** **Missing / Not Implemented (0%)**  

---

## 1. Executive Summary

This audit examined the administrative web interface to determine whether administrators can configure and manage the Freemium Access Management System.

Two admin repositories were located and inspected on disk:
1. **`tst-admin` (`admin_thestudentstimes.git`):** The modern, active administrative application built with Vite, React 19, Material-UI (MUI), and TanStack Query. Last active commit was on November 17, 2026 (`4a6e6ce add teacher module and classroom`).
2. **`3peas_admin` (`3peas_admin.git`):** A legacy CoreUI template repository last updated in December 2025 (`2879cb8 add new news modules`), prior to the adoption of `tst-admin`.

**Key Finding:**  
Neither `tst-admin` nor `3peas_admin` contains **any** implementation of Freemium management interfaces. There are zero components, routes, services, navigation items, or forms interfacing with the backend's `/api/v1/admin/freemium/*` APIs. Administrators currently have no visual interface to manage Freemium policies, campaigns, rules, quotas, grants, audits, or analytics.

---

## 2. Source Code Inspection Details (`tst-admin`)

### 2.1 Router Inspection: `src/routes/AppRouter.jsx`
- Contains 395 lines registering application routes for LMS, News, Users, Subscriptions, Teachers, Media, and Settings.
- **Freemium Search Results:** Zero occurrences of `freemium`, `campaign`, `quota`, `grant`, or `access-rule`.
- **Finding:** No routes exist to render Freemium administration screens.

### 2.2 Navigation Menu Inspection: `src/components/layout/Sidebar.jsx`
- Defines the hierarchical sidebar navigation tree across 531 lines.
- Navigation sections include:
  - `Dashboard` (`/`)
  - `News` (`/news/*`)
  - `LMS` (`/lms/*`: Academic Setup, Content Structure, Question Bank, Past Papers, Programs, LMS Users, Academic Reports, Question Reports)
  - `Media` (`/media/*`)
  - `Users` (`/users/*`: Admin Users, Passwords, Preferences, Students, Teachers, Roles & Permissions)
  - `Work` (`/work/*`: Data Entry Assignments, Payments)
  - `Subscriptions` (`/subscriptions/*`: Plans, Payments, Student Payment Requests, Coupons, Teacher Remittances)
  - `Teacher Subscriptions` (`/subscriptions/teacher-*`)
  - `Settings` (`/settings/*`: AI Providers, AI Models)
- **Finding:** No menu entry or submenu exists for Freemium, Campaigns, Access Rules, Quotas, Grants, or Freemium Audits.

### 2.3 API Services Inspection: `src/services/`
The API client layer contains 18 service files:
- `adminAuthApi.js` (Authentication)
- `academicReportsApi.js`
- `aiModelsApi.js`, `aiProvidersApi.js`, `aiQuestionGenerationApi.js`
- `api.js` (Axios / fetch wrapper)
- `dataEntryAssignmentsApi.js`
- `lmsApi.js` (LMS entities: boards, classes, subjects, books, units, topics, questions)
- `mediaApi.js`
- `newsApi.js`
- `questionExplanationApi.js`, `questionReportsApi.js`
- `sloDomainsApi.js`, `sloTopicsApi.js`
- `studentPaymentRequestsApi.js`
- `teacherCollectionPermissionsApi.js`, `teachersApi.js`, `teacherSubscriptionsApi.js`
- **Finding:** Zero API service files or helper functions interact with `/api/v1/admin/freemium/*`.

---

## 3. Evaluation of the 10 Required Admin Capabilities

| # | Admin Capability | Target Frontend Route | Target Component | Backend Endpoint | Status | Gaps Identified |
| :-: | :--- | :--- | :--- | :--- | :---: | :--- |
| **1** | **Freemium Dashboard** | None | None | `/api/v1/admin/freemium/analytics/summary` | **Missing** | No page displaying overview cards, active campaign counts, total free students, or quota consumption metrics. |
| **2** | **Analytics & Usage** | None | None | `/api/v1/admin/freemium/analytics/summary` | **Missing** | No visual charts, breakdowns by exam type (`formal_test`, `mock`, `practice`), or conversion statistics. |
| **3** | **Campaign Management** | None | None | `/api/v1/admin/freemium/campaigns` | **Missing** | No table listing campaigns, no create/edit modal, no status toggle (active/inactive), and no delete action. |
| **4** | **Campaign Schedules & Priority** | None | None | `/api/v1/admin/freemium/campaigns` | **Missing** | No date/time picker for `start_at` / `end_at`, priority input, or stacking toggle (`is_stackable`). |
| **5** | **Access Rules Management** | None | None | `/api/v1/admin/freemium/rules` | **Missing** | No UI to create rules by academic hierarchy (Board $\to$ Class $\to$ Subject $\to$ Book $\to$ Chapter $\to$ Topic $\to$ Question) or set allow/deny permissions. |
| **6** | **Quota Configuration** | None | None | `/api/v1/admin/freemium/quotas` | **Missing** | No form to configure weekly formal test, mock, or practice quotas, retake allowances, or attempt limits. |
| **7** | **Student Grants Management** | None | None | `/api/v1/admin/freemium/grants` | **Missing** | No interface to search students, assign campaign grants/vouchers, inspect active grants, or revoke them. |
| **8** | **Quota Consumption Inspection** | None | None | `/api/v1/admin/freemium/quotas` | **Missing** | No student consumption lookup tool to inspect counters, window keys, or remaining balances. |
| **9** | **Audit History View** | None | None | `/api/v1/admin/freemium/audit` | **Missing** | No audit log viewer to inspect administrative policy changes, actor ID, IP address, or before/after diffs. |
| **10**| **Freemium Permissions in UI** | `/users/roles` | `RolesPage.jsx` | `/api/v1/admin/roles` | **Partially Implemented** | Backend has permissions (`freemium.view`, `freemium.create`, `freemium.update`, `freemium.activate`, `freemium.delete`, `freemium.analytics`), but there is no Freemium module to grant them to. |

---

## 4. Admin Panel Audit Conclusion

The Admin Panel (`tst-admin`) currently provides **0% of the required Freemium administrative features**. 

All campaign, rule, quota, and grant management currently requires direct database queries, CLI Tinker sessions, or manual REST API requests via external tools like Postman. Building the Admin Panel interface is a critical dependency for non-technical administrators to manage Freemium campaigns.
