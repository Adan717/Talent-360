import { useState } from 'react';

// Extraído de useClockEngine.tsx (refactor Jul 2026): banderas booleanas puras de UI
// (modales, validaciones en curso) que no tenían ninguna lógica propia más allá de
// mostrarse/ocultarse. Mismos nombres y valores por defecto que antes, solo reubicados.
export function useClockUIState() {
  const [paseListaDone, setPaseListaDone] = useState(false);
  const [applyPunishments, setApplyPunishments] = useState(false);
  const [showMasterCloseModal, setShowMasterCloseModal] = useState(false);
  const [showTransferModal, setShowTransferModal] = useState(false);
  const [showCCTVModal, setShowCCTVModal] = useState(false);
  const [isDropdownOpen, setIsDropdownOpen] = useState(false);
  const [showAbsenceModal, setShowAbsenceModal] = useState(false);
  const [showAmnestyModal, setShowAmnestyModal] = useState(false);
  const [showGhostTheater, setShowGhostTheater] = useState(false);
  const [showJustificanteModal, setShowJustificanteModal] = useState(false);
  const [showReportModal, setShowReportModal] = useState(false);
  const [showEvalModal, setShowEvalModal] = useState(false);
  const [showForzosaModal, setShowForzosaModal] = useState(false);
  const [showPaseListaModal, setShowPaseListaModal] = useState(false);
  const [showBreakSeatModal, setShowBreakSeatModal] = useState(false);
  const [showTempExitModal, setShowTempExitModal] = useState(false);
  const [showPanicModal, setShowPanicModal] = useState(false);
  const [isPanicActive, setIsPanicActive] = useState(false);
  const [showMealSwapModal, setShowMealSwapModal] = useState(false);
  const [isHandoverCompleted, setIsHandoverCompleted] = useState(false);
  const [showEarlyDepartureModal, setShowEarlyDepartureModal] = useState(false);
  const [isEarlyDepartureValidation, setIsEarlyDepartureValidation] = useState(false);
  const [isOvertimeValidation, setIsOvertimeValidation] = useState(false);
  const [isLateEntryValidation, setIsLateEntryValidation] = useState(false);
  const [isSidebarOpen, setIsSidebarOpen] = useState(true);
  const [isModulesOpen, setIsModulesOpen] = useState(true);
  const [showMealReservationModal, setShowMealReservationModal] = useState(false);
  const [showAlarmSettingsModal, setShowAlarmSettingsModal] = useState(false);
  const [pendingTasksBlocker, setPendingTasksBlocker] = useState(false);
  const [preShiftAlarmPlayed, setPreShiftAlarmPlayed] = useState(false);
  const [mealReminderAlarmPlayed, setMealReminderAlarmPlayed] = useState(false);
  const [leySillaAlarmPlayed, setLeySillaAlarmPlayed] = useState(false);

  return {
    paseListaDone, setPaseListaDone,
    applyPunishments, setApplyPunishments,
    showMasterCloseModal, setShowMasterCloseModal,
    showTransferModal, setShowTransferModal,
    showCCTVModal, setShowCCTVModal,
    isDropdownOpen, setIsDropdownOpen,
    showAbsenceModal, setShowAbsenceModal,
    showAmnestyModal, setShowAmnestyModal,
    showGhostTheater, setShowGhostTheater,
    showJustificanteModal, setShowJustificanteModal,
    showReportModal, setShowReportModal,
    showEvalModal, setShowEvalModal,
    showForzosaModal, setShowForzosaModal,
    showPaseListaModal, setShowPaseListaModal,
    showBreakSeatModal, setShowBreakSeatModal,
    showTempExitModal, setShowTempExitModal,
    showPanicModal, setShowPanicModal,
    isPanicActive, setIsPanicActive,
    showMealSwapModal, setShowMealSwapModal,
    isHandoverCompleted, setIsHandoverCompleted,
    showEarlyDepartureModal, setShowEarlyDepartureModal,
    isEarlyDepartureValidation, setIsEarlyDepartureValidation,
    isOvertimeValidation, setIsOvertimeValidation,
    isLateEntryValidation, setIsLateEntryValidation,
    isSidebarOpen, setIsSidebarOpen,
    isModulesOpen, setIsModulesOpen,
    showMealReservationModal, setShowMealReservationModal,
    showAlarmSettingsModal, setShowAlarmSettingsModal,
    pendingTasksBlocker, setPendingTasksBlocker,
    preShiftAlarmPlayed, setPreShiftAlarmPlayed,
    mealReminderAlarmPlayed, setMealReminderAlarmPlayed,
    leySillaAlarmPlayed, setLeySillaAlarmPlayed,
  };
}
