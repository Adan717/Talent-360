import React, { useState, useRef, useEffect } from 'react';
import { Bot, X, Send, Sparkles, MessageSquare, AlertCircle } from 'lucide-react';
import axiosInstance from '../lib/axios';
import { useAppStore } from '../store/useAppStore';
 
interface Message {
    sender: 'user' | 'bot';
    text: string;
    timestamp: Date;
}
 
export const SupportChatCopilot = () => {
    const [isOpen, setIsOpen] = useState(false);
    const [messages, setMessages] = useState<Message[]>([
        {
            sender: 'bot',
            text: '¡Hola! Soy tu Copiloto de Soporte de Talent 360. 🤖 ¿En qué puedo ayudarte hoy? Puedo resolver dudas sobre el Reloj Checador V2, geolocalización, sincronización offline, planes de suscripción o la facturación CFDI 4.0.',
            timestamp: new Date()
        }
    ]);
    const [inputValue, setInputValue] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const messagesEndRef = useRef<HTMLDivElement>(null);
    const { currentUser } = useAppStore();
 
    const scrollToBottom = () => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    };
 
    useEffect(() => {
        if (isOpen) {
            scrollToBottom();
        }
    }, [messages, isOpen]);
 
    const handleSend = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!inputValue.trim() || isLoading) return;
 
        const userMsg = inputValue.trim();
        setInputValue('');
        setMessages(prev => [...prev, { sender: 'user', text: userMsg, timestamp: new Date() }]);
        setIsLoading(true);
 
        try {
            const response = await axiosInstance.post('/support/copilot', {
                question: userMsg,
                context: `Usuario actual: ${currentUser?.name || 'Desconocido'}, Email: ${currentUser?.email || 'N/A'}, Rol: ${currentUser?.role || 'N/A'}`
            });
 
            const botAnswer = response.data.answer || 'Lo siento, no he podido procesar tu solicitud.';
            setMessages(prev => [...prev, { sender: 'bot', text: botAnswer, timestamp: new Date() }]);
        } catch (error) {
            console.error('Error in Copilot support chat:', error);
            setMessages(prev => [
                ...prev,
                {
                    sender: 'bot',
                    text: '⚠️ Ocurrió un error al conectar con el servidor de inteligencia artificial. Por favor, inténtalo de nuevo.',
                    timestamp: new Date()
                }
            ]);
        } finally {
            setIsLoading(false);
        }
    };
 
    const createSupportTicketDirectly = async () => {
        try {
            setIsLoading(true);
            await axiosInstance.post('/platform/tickets', {
                title: `Ticket de Soporte Automático - ${currentUser?.name || 'Cliente'}`,
                description: `El usuario solicitó asistencia por chat de IA sobre: "${messages[messages.length - 2]?.text || 'Asistencia por chat'}"`,
                priority: 'medium',
                status: 'open',
                contact_name: currentUser?.name || 'Cliente',
                contact_email: currentUser?.email || 'soporte@talent360.com'
            });
            
            setMessages(prev => [
                ...prev,
                {
                    sender: 'bot',
                    text: '✅ ¡Ticket creado con éxito! Uno de nuestros agentes de soporte del Call Center le dará seguimiento de inmediato.',
                    timestamp: new Date()
                }
            ]);
        } catch (error) {
            console.error('Error creating ticket:', error);
        } finally {
            setIsLoading(false);
        }
    };
 
    return (
        <div className="fixed bottom-6 right-6 z-[9999] font-sans select-none">
            {/* Botón Flotante */}
            {!isOpen && (
                <button
                    onClick={() => setIsOpen(true)}
                    className="w-14 h-14 bg-gradient-to-tr from-violet-600 to-indigo-600 text-white rounded-full flex items-center justify-center shadow-2xl hover:scale-108 transition-all active:scale-95 duration-300 relative group cursor-pointer border-none outline-none"
                    title="Copiloto de Soporte"
                >
                    <div className="absolute inset-0 bg-violet-400 rounded-full blur-[8px] opacity-40 group-hover:opacity-75 transition-opacity pointer-events-none animate-pulse"></div>
                    <Bot size={26} className="relative z-10 animate-pulse" />
                    <span className="absolute -top-1 -right-1 w-3.5 h-3.5 bg-emerald-500 rounded-full border-2 border-white animate-ping"></span>
                    <span className="absolute -top-1 -right-1 w-3.5 h-3.5 bg-emerald-500 rounded-full border-2 border-white"></span>
                </button>
            )}
 
            {/* Ventana de Chat */}
            {isOpen && (
                <div className="w-[360px] h-[500px] sm:w-[400px] bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl flex flex-col overflow-hidden animate-in slide-in-from-bottom-5 duration-300 backdrop-blur-md">
                    {/* Header */}
                    <div className="bg-gradient-to-r from-violet-600 via-indigo-600 to-violet-600 px-5 py-4 flex items-center justify-between text-white shrink-0 shadow-md">
                        <div className="flex items-center gap-3 text-left">
                            <div className="w-10 h-10 rounded-2xl bg-white/10 flex items-center justify-center">
                                <Bot size={22} className="animate-pulse" />
                            </div>
                            <div>
                                <h3 className="text-sm font-black tracking-wide uppercase leading-none">Copiloto AI</h3>
                                <p className="text-[10px] text-violet-200 mt-1 flex items-center gap-1">
                                    <Sparkles size={10} className="animate-bounce" />
                                    Soporte Técnico Activo
                                </p>
                            </div>
                        </div>
                        <button
                            onClick={() => setIsOpen(false)}
                            className="bg-white/10 hover:bg-white/20 text-white/90 p-1.5 rounded-full transition-colors border-none cursor-pointer outline-none"
                        >
                            <X size={18} />
                        </button>
                    </div>
 
                    {/* Mensajes */}
                    <div className="flex-grow p-4 overflow-y-auto space-y-3.5 custom-scrollbar bg-slate-50/50 dark:bg-slate-950/20">
                        {messages.map((msg, idx) => (
                            <div
                                key={idx}
                                className={`flex ${msg.sender === 'user' ? 'justify-end' : 'justify-start'} animate-in fade-in-50 duration-200`}
                            >
                                <div
                                    className={`max-w-[82%] px-4 py-3 rounded-2xl text-xs leading-relaxed text-left ${
                                        msg.sender === 'user'
                                            ? 'bg-violet-600 text-white rounded-br-none shadow-md shadow-violet-600/10'
                                            : 'bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-200 border border-slate-100 dark:border-slate-700/80 rounded-bl-none shadow-sm'
                                    }`}
                                >
                                    {msg.text}
                                    <span
                                        className={`block text-[9px] mt-1.5 text-right font-medium ${
                                            msg.sender === 'user' ? 'text-white/60' : 'text-slate-400'
                                        }`}
                                    >
                                        {msg.timestamp.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                    </span>
                                </div>
                            </div>
                        ))}
                        
                        {isLoading && (
                            <div className="flex justify-start">
                                <div className="bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700/85 px-4 py-3 rounded-2xl rounded-bl-none shadow-sm flex items-center gap-1">
                                    <div className="w-1.5 h-1.5 bg-violet-500 rounded-full animate-bounce" style={{ animationDelay: '0ms' }}></div>
                                    <div className="w-1.5 h-1.5 bg-violet-500 rounded-full animate-bounce" style={{ animationDelay: '150ms' }}></div>
                                    <div className="w-1.5 h-1.5 bg-violet-500 rounded-full animate-bounce" style={{ animationDelay: '300ms' }}></div>
                                </div>
                            </div>
                        )}
                        <div ref={messagesEndRef} />
                    </div>
 
                    {/* Botones de acción rápida */}
                    {messages.length > 2 && !isLoading && (
                        <div className="px-4 py-2 border-t border-slate-100 dark:border-slate-800/80 bg-white/40 dark:bg-slate-900/40 flex items-center justify-center shrink-0">
                            <button
                                onClick={createSupportTicketDirectly}
                                className="flex items-center gap-1.5 px-3 py-1.5 bg-rose-50 hover:bg-rose-100 dark:bg-rose-955/20 text-rose-600 dark:text-rose-400 font-extrabold text-[10px] uppercase tracking-wider rounded-xl transition-all border border-rose-200/50 cursor-pointer outline-none active:scale-95"
                            >
                                <MessageSquare size={12} />
                                ¿Aún tienes dudas? Crear Ticket
                            </button>
                        </div>
                    )}
 
                    {/* Input Area */}
                    <form
                        onSubmit={handleSend}
                        className="p-3 border-t border-slate-100 dark:border-slate-800/80 bg-white dark:bg-slate-900 flex gap-2 shrink-0"
                    >
                        <input
                            type="text"
                            value={inputValue}
                            onChange={(e) => setInputValue(e.target.value)}
                            placeholder="Escribe tu consulta de soporte..."
                            className="flex-1 px-4 py-2.5 bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-200 text-xs rounded-xl border border-slate-200/60 dark:border-slate-800 focus:outline-none focus:border-violet-500"
                        />
                        <button
                            type="submit"
                            disabled={!inputValue.trim() || isLoading}
                            className="p-2.5 bg-violet-600 hover:bg-violet-750 text-white rounded-xl flex items-center justify-center transition-all disabled:opacity-40 disabled:hover:bg-violet-600 border-none cursor-pointer outline-none"
                        >
                            <Send size={15} />
                        </button>
                    </form>
                </div>
            )}
        </div>
    );
};
