//global React
import { useEffect, useState } from 'react';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import { api, clearToken, getToken, saveToken } from './api.js';
import {
    AlertDetailPage,
    AlertFormPage,
    AlertsPage,
    AuditPage,
    DashboardPage,
    HazardSourcesPage,
    LandingPage,
    LoginPage,
    NotFoundPage,
    ProfilePage,
    RegisterPage,
    ResidentAlertPage,
    ResidentAlertsPage,
    RiskAreasPage,
    StaffFormPage,
    StaffPage,
} from './pages.jsx';
import { LoadingState, Notice, roleLabel } from './ui.jsx';

const staffRoles = ['system_admin', 'mdrrmo', 'bfp_officer', 'alert_operator'];
const alertRoles = ['system_admin', 'mdrrmo', 'alert_operator'];

function unreadAlertContent(alerts) {
    const content = document.createElement('div');
    const description = document.createElement('p');
    const list = document.createElement('div');

    content.className = 'swal-advisory-content';
    description.textContent = 'Select an advisory to read it. Opening one marks it as read.';
    list.className = 'swal-advisory-list';

    alerts.forEach((alert) => {
        const link = document.createElement('a');
        link.href = `/my-alerts/${encodeURIComponent(alert.id)}`;
        link.textContent = alert.title;
        list.append(link);
    });

    content.append(description, list);

    return content;
}

function Navigation({ user, onLogout }) {
    const isResident = user.role === 'resident';
    const isAdmin = user.role === 'system_admin';

    return (
        <aside className="sidebar">
            <a className="brand" href="/dashboard">
                <span className="brand-mark">K</span>
                <span>Kalinga<span className="brand-light">Alert</span></span>
            </a>
            <div className="sidebar-label">WORKSPACE</div>
            <nav className="side-nav">
                <a href="/dashboard">Overview</a>
                {isResident ? (
                    <a href="/my-alerts">My alerts</a>
                ) : (
                    <>
                        {alertRoles.includes(user.role) && <a href="/alerts">Advisories</a>}
                        {staffRoles.includes(user.role) && <a href="/hazard-sources">Official sources</a>}
                        {staffRoles.includes(user.role) && <a href="/risk-areas">Risk areas</a>}
                        {isAdmin && <a href="/staff">Staff accounts</a>}
                        {isAdmin && <a href="/staff/audit">Audit history</a>}
                    </>
                )}
                <a href="/profile">My profile</a>
            </nav>
            <div className="sidebar-footer">
                <div className="user-chip">
                    <span className="avatar">{user.name?.slice(0, 1).toUpperCase()}</span>
                    <span><strong>{user.name}</strong><small>{roleLabel(user.role)}</small></span>
                </div>
                <button className="button button-quiet button-full" onClick={onLogout} type="button">Sign out</button>
            </div>
        </aside>
    );
}

function Shell({ user, onLogout, children }) {
    return (
        <div className="app-shell">
            <Navigation user={user} onLogout={onLogout} />
            <div className="main-column">
                <header className="topbar">
                    <span className="topbar-location">Bongabong <span>/</span> Oriental Mindoro</span>
                    <span className="secure-label"><i /> Verified information workspace</span>
                </header>
                <main className="main-content">{children}</main>
                <footer className="app-footer">KalingaAlert · Official sources only · Not a replacement for emergency services</footer>
            </div>
        </div>
    );
}

function GuestFrame({ children }) {
    return (
        <div className="guest-page">
            <header className="guest-header">
                <a className="brand" href="/">
                    <span className="brand-mark">K</span>
                    <span>Kalinga<span className="brand-light">Alert</span></span>
                </a>
                <nav><a href="/login">Sign in</a><a className="button button-small" href="/register">Resident registration</a></nav>
            </header>
            {children}
            <footer className="guest-footer">KalingaAlert · Bongabong, Oriental Mindoro</footer>
        </div>
    );
}

function routeFor(path) {
    if (path === '/dashboard') return { component: DashboardPage, roles: [...staffRoles, 'resident'] };
    if (path === '/alerts') return { component: AlertsPage, roles: alertRoles };
    if (path === '/alerts/create') return { component: AlertFormPage, roles: alertRoles };
    if (/^\/alerts\/\d+\/edit$/.test(path)) {
        return { component: AlertFormPage, roles: alertRoles, id: path.split('/')[2] };
    }
    if (/^\/alerts\/\d+$/.test(path)) {
        return { component: AlertDetailPage, roles: alertRoles, id: path.split('/')[2] };
    }
    if (path === '/my-alerts') return { component: ResidentAlertsPage, roles: ['resident'] };
    if (/^\/my-alerts\/\d+$/.test(path)) {
        return { component: ResidentAlertPage, roles: ['resident'], id: path.split('/')[2] };
    }
    if (path === '/hazard-sources') {
        return { component: HazardSourcesPage, roles: staffRoles };
    }
    if (path === '/hazard-sources/create') {
        return { component: HazardSourcesPage, roles: ['system_admin', 'mdrrmo', 'bfp_officer'] };
    }
    if (/^\/hazard-sources\/\d+\/review$/.test(path)) {
        return { component: HazardSourcesPage, roles: staffRoles, id: path.split('/')[2] };
    }
    if (path === '/risk-areas') {
        return { component: RiskAreasPage, roles: staffRoles };
    }
    if (path === '/risk-areas/create' || /^\/risk-areas\/\d+\/edit$/.test(path)) {
        return {
            component: RiskAreasPage,
            roles: ['system_admin', 'mdrrmo'],
            id: path.split('/')[2] === 'create' ? null : path.split('/')[2],
        };
    }
    if (path === '/profile') return { component: ProfilePage, roles: [...staffRoles, 'resident'] };
    if (path === '/staff' || path === '/staff/create') {
        return { component: path === '/staff' ? StaffPage : StaffFormPage, roles: ['system_admin'] };
    }
    if (path === '/staff/audit') return { component: AuditPage, roles: ['system_admin'] };

    return { component: NotFoundPage, roles: [...staffRoles, 'resident'] };
}

export default function App() {
    const [user, setUser] = useState(null);
    const [checkingAuth, setCheckingAuth] = useState(Boolean(getToken()));
    const [authError, setAuthError] = useState('');
    const path = window.location.pathname.replace(/\/+$/, '') || '/';

    useEffect(() => {
        if (!getToken()) {
            return;
        }

        api('/me')
            .then((response) => setUser(response.data))
            .catch((error) => {
                clearToken();
                setAuthError(error.message);
            })
            .finally(() => setCheckingAuth(false));
    }, []);

    useEffect(() => {
        if (user?.role !== 'resident') {
            return undefined;
        }

        let isCurrent = true;

        async function showUnreadAlerts() {
            try {
                const status = await api('/my/alerts/status');
                const alerts = status.alerts ?? [];
                const unreadCount = Number(status.unread_count ?? alerts.length);

                if (!isCurrent || unreadCount <= 0 || alerts.length === 0) {
                    return;
                }

                const result = await Swal.fire({
                    icon: 'info',
                    title: `${unreadCount} unread community ${unreadCount === 1 ? 'advisory' : 'advisories'}`,
                    html: unreadAlertContent(alerts),
                    showCancelButton: true,
                    confirmButtonText: 'View all advisories',
                    cancelButtonText: 'Close for now',
                    confirmButtonColor: '#1d5b45',
                });

                if (isCurrent && result.isConfirmed) {
                    window.location.assign('/my-alerts');
                }
            } catch (error) {
                if (isCurrent) {
                    await Swal.fire({
                        icon: 'error',
                        title: 'Unable to check notifications',
                        text: error.message,
                        confirmButtonColor: '#1d5b45',
                    });
                }
            }
        }

        showUnreadAlerts();

        return () => {
            isCurrent = false;
            Swal.close();
        };
    }, [user?.id, user?.role]);

    
    async function signOut() {
        try {
            await api('/auth/logout', { method: 'POST' });
        } catch (error) {
            setAuthError(error.message);
        } finally {
            clearToken();
            setUser(null);
            window.location.assign('/');
        }
    }

    if (checkingAuth) {
        return <div className="screen-loading"><LoadingState label="Checking your account…" /></div>;
    }

    if (path === '/' && !user) {
        return <GuestFrame><LandingPage /></GuestFrame>;
    }

    if (path === '/login' && !user) {
        return <GuestFrame><LoginPage onAuthenticated={(payload) => {
            saveToken(payload.access_token);
            setUser(payload.data);
            window.location.assign('/dashboard');
        }} notice={authError} /></GuestFrame>;
    }

    if (path === '/register' && !user) {
        return <GuestFrame><RegisterPage onAuthenticated={(payload) => {
            saveToken(payload.access_token);
            setUser(payload.data);
            window.location.assign('/dashboard');
        }} /></GuestFrame>;
    }

    if (!user) {
        return (
            <GuestFrame>
                <section className="auth-card">
                    <Notice>{authError}</Notice>
                    <h1>Sign in to continue</h1>
                    <p className="muted">This section is available to registered residents and authorized staff.</p>
                    <a className="button" href="/login">Go to sign in</a>
                </section>
            </GuestFrame>
        );
    }

    const route = routeFor(path);

    if (!route.roles.includes(user.role)) {
        return <Shell user={user} onLogout={signOut}><section className="panel"><h1>Access unavailable</h1><p className="muted">Your account does not have permission to open this section.</p></section></Shell>;
    }

    const Page = route.component;

    return <Shell user={user} onLogout={signOut}><Page user={user} id={route.id} /></Shell>;
}
