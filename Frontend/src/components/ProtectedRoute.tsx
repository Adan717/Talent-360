import React, { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAppStore } from '../store/useAppStore';
import { LoadingScreen } from './ui/LoadingScreen';

interface ProtectedRouteProps {
  allowedRoles?: string[];
  children: React.ReactNode;
}

export const ProtectedRoute = ({ allowedRoles, children }: ProtectedRouteProps) => {
  const { currentUser, isLoadingDB } = useAppStore();
  const navigate = useNavigate();
  const hasToken = !!localStorage.getItem('talent_auth_token');

  useEffect(() => {
    if (!hasToken) {
      navigate('/login', { replace: true });
      return;
    }

    if (!isLoadingDB && currentUser?.role !== 'Loading') {
      if (allowedRoles && !allowedRoles.includes(currentUser?.system_role || '')) {
        if (currentUser?.system_role === 'platform_admin' || currentUser?.system_role === 'support_agent') {
          navigate('/superadmin', { replace: true });
        } else if (currentUser?.system_role === 'empleado') {
          navigate('/empleado', { replace: true });
        } else {
          navigate('/', { replace: true });
        }
      }
    }
  }, [hasToken, isLoadingDB, currentUser, allowedRoles, navigate]);

  if (!hasToken) {
    return null;
  }

  if (isLoadingDB && currentUser?.role === 'Loading') {
    return <LoadingScreen message="Verificando sesión..." fullScreen={true} />;
  }

  if (allowedRoles && !allowedRoles.includes(currentUser?.system_role || '')) {
    return null;
  }

  return <>{children}</>;
};
