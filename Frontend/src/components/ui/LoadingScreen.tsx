import React from 'react';
import { LayoutDashboard } from 'lucide-react';

interface LoadingScreenProps {
  message?: string;
  fullScreen?: boolean;
}

export const LoadingScreen: React.FC<LoadingScreenProps> = ({ 
  message = 'Cargando módulo...', 
  fullScreen = false 
}) => {
  return (
    <div 
      className={`flex flex-col items-center justify-center transition-all duration-300 ${
        fullScreen 
          ? 'fixed inset-0 w-screen h-screen z-50 bg-slate-50/90 backdrop-blur-sm' 
          : 'w-full h-full min-h-[250px] md:min-h-[400px] py-12 px-4 bg-transparent'
      }`}
    >
      <div className="flex flex-col items-center justify-center text-center max-w-xs sm:max-w-sm md:max-w-md w-full">
        <div className="animate-spin text-slate-400 mb-4 md:mb-6">
          <LayoutDashboard className="w-10 h-10 sm:w-12 sm:h-12 md:w-14 md:h-14 stroke-[1.5]" />
        </div>
        <p className="font-semibold text-sm sm:text-base text-slate-500 tracking-wide select-none">
          {message}
        </p>
      </div>
    </div>
  );
};
