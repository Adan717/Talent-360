
const MOCK_USERS = [
  { id: 1, name: "Francisco", role: "Administrador / Gerente", area: "Administración", avatar: "https://i.pravatar.cc/150?u=admin", shiftStart: "08:20", shiftEnd: "18:00", mealMinutes: 60, restDay: "Domingo" },
  { id: 2, name: "Liz", role: "Sup. Tienda y Compras", area: "Piso", avatar: "https://i.pravatar.cc/150?u=suptienda", shiftStart: "08:20", shiftEnd: "18:00", mealMinutes: 60, restDay: "Lunes" },
  { id: 3, name: "Joseline", role: "Sup. Cajas", area: "Cajas", avatar: "https://i.pravatar.cc/150?u=supcajas", shiftStart: "08:20", shiftEnd: "18:00", mealMinutes: 60, restDay: "Martes" },
  { id: 4, name: "Hiraym", role: "Sup. Producción", area: "Producción", avatar: "https://i.pravatar.cc/150?u=produccion", shiftStart: "09:00", shiftEnd: "18:00", mealMinutes: 60, restDay: "Miércoles" },
  { id: 5, name: "Agnela", role: "Cajeros", area: "Cajas", avatar: "https://i.pravatar.cc/150?u=cajero1", shiftStart: "08:30", shiftEnd: "17:00", mealMinutes: 30, restDay: "Domingo" },
  { id: 6, name: "Adriana", role: "Cajeros", area: "Cajas", avatar: "https://i.pravatar.cc/150?u=cajero2", shiftStart: "08:30", shiftEnd: "17:00", mealMinutes: 30, restDay: "Lunes" },
  { id: 7, name: "Cristina", role: "Ayudante Integral", area: "Piso", avatar: "https://i.pravatar.cc/150?u=ayudante1", shiftStart: "08:30", shiftEnd: "17:00", mealMinutes: 30, restDay: "Martes" },
  { id: 8, name: "Valeria", role: "Ayudante Integral", area: "Piso", avatar: "https://i.pravatar.cc/150?u=ayudante2", shiftStart: "09:00", shiftEnd: "17:30", mealMinutes: 30, restDay: "Miércoles" },
  { id: 9, name: "Manuel", role: "Cajeros", area: "Cajas", avatar: "https://i.pravatar.cc/150?u=cajero3", shiftStart: "08:30", shiftEnd: "17:00", mealMinutes: 30, restDay: "Jueves" }
];

const MOCK_SHIFTS = {
    1: { start: "08:20", end: "18:00", restDay: "Domingo" },
    2: { start: "08:20", end: "18:00", restDay: "Lunes" },
    3: { start: "08:20", end: "18:00", restDay: "Martes" },
    4: { start: "09:00", end: "18:00", restDay: "Miércoles" },
    5: { start: "08:30", end: "17:00", restDay: "Domingo" },
    6: { start: "08:30", end: "17:00", restDay: "Lunes" },
    7: { start: "08:30", end: "17:00", restDay: "Martes" },
    8: { start: "09:00", end: "17:30", restDay: "Miércoles" },
    9: { start: "08:30", end: "17:00", restDay: "Jueves" }
};

fetch('http://127.0.0.1:8000/api/sync/init', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ users: MOCK_USERS, configs: MOCK_SHIFTS })
})
.then(r => r.json())
.then(console.log)
.catch(console.error);
