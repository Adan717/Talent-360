import React from 'react';

interface CertificadoProps {
  participantName: string;
  courseName: string;
  startDate: string;
  endDate: string;
  month: string;
  year: string;
  template?: any;
}

export const CertificadoImprimible: React.FC<CertificadoProps> = ({
  participantName,
  courseName,
  startDate,
  endDate,
  month,
  year,
  template
}) => {
  const companyName = template?.company_name || 'Talent360';
  const location = template?.location || 'Irapuato, Gto.';
  const logoUrl = template?.logo_url;
  const directorName = template?.director_name || 'Director General';
  const instructorName = template?.instructor_name || 'Instructor Jefe';
  const directorSig = template?.director_signature_url;
  const instructorSig = template?.instructor_signature_url;
  
  const primaryColor = template?.primary_color || '#6b1e2e';
  const secondaryColor = template?.secondary_color || '#b58c4c';

  return (
    <div className="printable-certificate w-[297mm] h-[210mm] mx-auto bg-[#fffdf8] relative flex flex-col justify-between overflow-hidden" style={{ fontFamily: 'Times New Roman, serif' }}>
      
      {/* Fondo y Bordes */}
      <div className="absolute inset-0 pointer-events-none z-0 p-4">
        {/* Borde exterior grueso (primaryColor) */}
        <div className="w-full h-full border-[10px] relative p-1" style={{ borderColor: primaryColor }}>
          {/* Borde interior delgado (secondaryColor) */}
          <div className="w-full h-full border-2 relative" style={{ borderColor: secondaryColor }}>
            {/* Esquinas ornamentales */}
            <div className="absolute top-0 left-0 w-16 h-16 border-t-4 border-l-4 rounded-tl-[40px]" style={{ borderColor: secondaryColor }}></div>
            <div className="absolute top-0 right-0 w-16 h-16 border-t-4 border-r-4 rounded-tr-[40px]" style={{ borderColor: secondaryColor }}></div>
            <div className="absolute bottom-0 left-0 w-16 h-16 border-b-4 border-l-4 rounded-bl-[40px]" style={{ borderColor: secondaryColor }}></div>
            <div className="absolute bottom-0 right-0 w-16 h-16 border-b-4 border-r-4 rounded-br-[40px]" style={{ borderColor: secondaryColor }}></div>
          </div>
        </div>
      </div>

      <div className="relative z-10 flex flex-col items-center justify-start h-full pt-10 pb-8 px-24 text-center">
        
        {/* LOGO */}
        <div className="w-32 h-32 mb-4 bg-white rounded-full border border-slate-200 flex items-center justify-center overflow-hidden shadow-sm shrink-0">
          {logoUrl ? (
            <img src={logoUrl} alt="Company Logo" className="w-full h-full object-contain p-2" />
          ) : (
            <div className="font-black text-2xl tracking-tighter leading-none text-center" style={{ color: primaryColor }}>
              Decor<br/><span style={{ color: secondaryColor }}>Arte</span>
            </div>
          )}
        </div>

        {/* TITULOS */}
        <div className="flex items-center justify-center gap-6 mb-2 shrink-0">
          <div className="text-4xl" style={{ color: secondaryColor }}>~</div>
          <h1 className="text-5xl font-bold tracking-widest font-serif uppercase" style={{ color: secondaryColor }}>
            Certificado
          </h1>
          <div className="text-4xl" style={{ color: secondaryColor }}>~</div>
        </div>
        <h2 className="text-3xl font-bold tracking-wider font-serif uppercase mb-6 shrink-0" style={{ color: primaryColor }}>
          De Aprobación de Curso
        </h2>

        {/* CONTENIDO TEXTO */}
        <p className="text-lg text-slate-800 mb-2 shrink-0">
          En {companyName} nos complace otorgar el presente a:
        </p>

        {/* NOMBRE PARTICIPANTE */}
        <div className="w-full border-b border-slate-300 pb-1 mb-4 shrink-0">
          <h3 className="text-5xl text-slate-900" style={{ fontFamily: 'Brush Script MT, cursive', fontStyle: 'italic' }}>
            {participantName}
          </h3>
        </div>

        <p className="text-md text-slate-800 mb-2 shrink-0">
          Por su destacada participación y exitosa conclusión del curso integral de capacitación:
        </p>

        {/* NOMBRE CURSO */}
        <div className="w-full border-b border-slate-300 pb-1 mb-4 shrink-0">
          <h4 className="text-3xl" style={{ fontFamily: 'Brush Script MT, cursive', fontStyle: 'italic', color: primaryColor }}>
            {courseName}
          </h4>
        </div>

        <p className="text-sm text-slate-700 leading-relaxed mb-2 px-12 shrink-0">
          Cubriendo satisfactoriamente todos los módulos teóricos y prácticos requeridos.
        </p>
        <p className="text-sm text-slate-700 leading-relaxed mb-4 shrink-0">
          Impartido del <strong>{startDate}</strong> al <strong>{endDate}</strong> de <strong>{month}</strong>, <strong>{year}</strong>, en las instalaciones de {companyName}, {location}.
        </p>

        <p className="text-sm text-slate-800 italic font-medium shrink-0">
          Su dedicación y compromiso con la excelencia demuestran su pasión por el aprendizaje constante.
          <br/>¡Felicidades por este gran logro!
        </p>

        {/* FIRMAS Y SELLO EN EL FOOTER */}
        <div className="flex w-full justify-between items-end mt-auto px-12 shrink-0">
          {/* Firma Instructor */}
          <div className="flex flex-col items-center w-56">
            <div className="h-16 flex items-end justify-center w-full mb-1">
              {instructorSig ? (
                <img src={instructorSig} alt="Firma Instructor" className="max-h-full max-w-full object-contain" />
              ) : (
                <div className="text-slate-300 font-serif italic text-xl">Firma aquí</div>
              )}
            </div>
            <div className="w-full border-t border-slate-400 pt-1">
              <p className="font-bold text-xs uppercase text-slate-800">{instructorName}</p>
              <p className="text-[10px] text-slate-500 uppercase">Instructor / Aval</p>
            </div>
          </div>

          {/* SELLO DORADO (Simulado) */}
          <div className="relative flex flex-col items-center justify-center -mb-6">
            <div className="w-20 h-20 rounded-full border-2 border-dashed border-[#fffdf8] flex items-center justify-center shadow-lg relative z-10" style={{ backgroundImage: `linear-gradient(to bottom right, ${secondaryColor}, #aa7f1d, ${secondaryColor})` }}>
              <div className="w-14 h-14 rounded-full flex items-center justify-center border" style={{ backgroundColor: '#aa7f1d', borderColor: secondaryColor }}>
                <span className="text-[#fffdf8] text-[9px] font-black text-center leading-tight">SELLO<br/>OFICIAL</span>
              </div>
            </div>
            {/* Listones del sello */}
            <div className="flex gap-2 -mt-3 relative z-0">
               <div className="w-5 h-10 transform skew-y-12" style={{ backgroundColor: primaryColor }}></div>
               <div className="w-5 h-10 transform -skew-y-12" style={{ backgroundColor: primaryColor }}></div>
            </div>
          </div>

          {/* Firma Director */}
          <div className="flex flex-col items-center w-56">
            <div className="h-16 flex items-end justify-center w-full mb-1">
              {directorSig ? (
                <img src={directorSig} alt="Firma Director" className="max-h-full max-w-full object-contain" />
              ) : (
                <div className="text-slate-300 font-serif italic text-xl">Firma aquí</div>
              )}
            </div>
            <div className="w-full border-t border-slate-400 pt-1">
              <p className="font-bold text-xs uppercase text-slate-800">{directorName}</p>
              <p className="text-[10px] text-slate-500 uppercase">Director General</p>
            </div>
          </div>
        </div>

      </div>
    </div>
  );
};
