import { useEffect, useState } from 'react'

const SIGUIENTES = {
  programada: ['confirmada', 'cancelada'],
  pendiente_confirmacion: ['confirmada', 'cancelada'],
  confirmada: ['en_atencion', 'cancelada', 'no_asistio'],
  en_atencion: ['completada'],
}
const CLINICOS = ['en_atencion', 'completada']

async function api(token, ruta, opciones = {}) {
  const res = await fetch('/api' + ruta, {
    ...opciones,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(token ? { Authorization: 'Bearer ' + token } : {}),
    },
  })
  const datos = res.status === 204 ? null : await res.json().catch(() => null)
  if (!res.ok) {
    const detalle = datos?.errors ? Object.values(datos.errors).flat().join(' ') : datos?.mensaje || datos?.message
    throw new Error(detalle || 'Error ' + res.status)
  }
  return datos
}

function Paginacion({ pagina, ultima, onCambiar }) {
  return (
    <div className="fila">
      <button disabled={pagina <= 1} onClick={() => onCambiar(pagina - 1)}>Anterior</button>
      <span>Página {pagina} de {ultima}</span>
      <button disabled={pagina >= ultima} onClick={() => onCambiar(pagina + 1)}>Siguiente</button>
    </div>
  )
}

function Login({ onLogin }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function enviar(e) {
    e.preventDefault()
    try {
      const d = await api(null, '/login', { method: 'POST', body: JSON.stringify({ email, password }) })
      onLogin(d.token, d.rol)
    } catch (err) {
      setError(err.message)
    }
  }

  return (
    <div className="login">
      <h2>Consultorio dental</h2>
      <form className="fila" onSubmit={enviar}>
        <input placeholder="Correo" value={email} onChange={(e) => setEmail(e.target.value)} />
        <input type="password" placeholder="Contraseña" value={password} onChange={(e) => setPassword(e.target.value)} />
        <button>Entrar</button>
      </form>
      {error && <div className="error">{error}</div>}
    </div>
  )
}

function Pacientes({ token, rol }) {
  const [lista, setLista] = useState([])
  const [buscar, setBuscar] = useState('')
  const [pagina, setPagina] = useState(1)
  const [ultima, setUltima] = useState(1)
  const [nuevo, setNuevo] = useState({ nombre: '', telefono: '', email: '' })
  const [error, setError] = useState('')
  const puedeEditar = ['recepcion', 'admin'].includes(rol)

  async function cargar(pag = pagina) {
    try {
      const d = await api(token, '/pacientes?page=' + pag + '&buscar=' + encodeURIComponent(buscar))
      setLista(d.data)
      setPagina(d.current_page)
      setUltima(d.last_page)
      setError('')
    } catch (e) {
      setError(e.message)
    }
  }
  useEffect(() => { cargar() }, [])

  async function crear(e) {
    e.preventDefault()
    try {
      await api(token, '/pacientes', { method: 'POST', body: JSON.stringify(nuevo) })
      setNuevo({ nombre: '', telefono: '', email: '' })
      cargar()
    } catch (err) {
      setError(err.message)
    }
  }

  return (
    <div>
      <h3>Pacientes</h3>
      <form className="fila" onSubmit={(e) => { e.preventDefault(); cargar(1) }}>
        <input placeholder="Buscar por nombre" value={buscar} onChange={(e) => setBuscar(e.target.value)} />
        <button>Buscar</button>
      </form>
      {puedeEditar && (
        <form className="fila" onSubmit={crear}>
          <input placeholder="Nombre" value={nuevo.nombre} onChange={(e) => setNuevo({ ...nuevo, nombre: e.target.value })} />
          <input placeholder="Teléfono" value={nuevo.telefono} onChange={(e) => setNuevo({ ...nuevo, telefono: e.target.value })} />
          <input placeholder="Correo" value={nuevo.email} onChange={(e) => setNuevo({ ...nuevo, email: e.target.value })} />
          <button>Agregar</button>
        </form>
      )}
      {error && <div className="error">{error}</div>}
      <table>
        <thead><tr><th>ID</th><th>Nombre</th><th>Teléfono</th><th>Correo</th></tr></thead>
        <tbody>
          {lista.map((p) => (
            <tr key={p.id}><td>{p.id}</td><td>{p.nombre}</td><td>{p.telefono}</td><td>{p.email}</td></tr>
          ))}
        </tbody>
      </table>
      <Paginacion pagina={pagina} ultima={ultima} onCambiar={cargar} />
    </div>
  )
}

function Citas({ token, rol }) {
  const [lista, setLista] = useState([])
  const [nueva, setNueva] = useState({ paciente_id: '', dentista_id: '', inicio: '', duracion_min: 30 })
  const [error, setError] = useState('')
  const [filtroEstado, setFiltroEstado] = useState('')
  const [pagina, setPagina] = useState(1)
  const [ultima, setUltima] = useState(1)
  const puedeCrear = ['recepcion', 'admin'].includes(rol)

  async function cargar(pag = pagina, estado = filtroEstado) {
    try {
      const d = await api(token, '/citas?page=' + pag + (estado ? '&estado=' + estado : ''))
      setLista(d.data)
      setPagina(d.current_page)
      setUltima(d.last_page)
      setError('')
    } catch (e) {
      setError(e.message)
    }
  }
  useEffect(() => { cargar() }, [])

  async function crear(e) {
    e.preventDefault()
    try {
      await api(token, '/citas', {
        method: 'POST',
        body: JSON.stringify({
          paciente_id: Number(nueva.paciente_id),
          dentista_id: Number(nueva.dentista_id),
          inicio: nueva.inicio,
          duracion_min: Number(nueva.duracion_min),
        }),
      })
      cargar()
    } catch (err) {
      setError(err.message)
    }
  }

  async function cambiar(id, estado) {
    try {
      await api(token, '/citas/' + id + '/estado', { method: 'POST', body: JSON.stringify({ estado }) })
      cargar()
    } catch (err) {
      setError(err.message)
    }
  }

  const permitidos = (c) =>
    (SIGUIENTES[c.estado] || []).filter((s) => (CLINICOS.includes(s) ? rol === 'dentista' : rol !== 'dentista'))

  return (
    <div>
      <h3>Citas</h3>
      {puedeCrear && (
        <form className="fila" onSubmit={crear}>
          <input placeholder="ID paciente" value={nueva.paciente_id} onChange={(e) => setNueva({ ...nueva, paciente_id: e.target.value })} />
          <input placeholder="ID dentista" value={nueva.dentista_id} onChange={(e) => setNueva({ ...nueva, dentista_id: e.target.value })} />
          <input type="datetime-local" value={nueva.inicio} onChange={(e) => setNueva({ ...nueva, inicio: e.target.value })} />
          <select value={nueva.duracion_min} onChange={(e) => setNueva({ ...nueva, duracion_min: e.target.value })}>
            <option value={30}>30 min</option>
            <option value={60}>60 min</option>
            <option value={90}>90 min</option>
          </select>
          <button>Agendar</button>
        </form>
      )}
      <div className="fila">
        <select value={filtroEstado} onChange={(e) => { setFiltroEstado(e.target.value); cargar(1, e.target.value) }}>
          <option value="">Todos los estados</option>
          {['programada', 'pendiente_confirmacion', 'confirmada', 'en_atencion', 'completada', 'cancelada', 'no_asistio'].map((e) => (
            <option key={e} value={e}>{e}</option>
          ))}
        </select>
      </div>
      {error && <div className="error">{error}</div>}
      <table>
        <thead><tr><th>ID</th><th>Paciente</th><th>Dentista</th><th>Inicio</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody>
          {lista.map((c) => (
            <tr key={c.id}>
              <td>{c.id}</td>
              <td>{c.paciente_id}</td>
              <td>{c.dentista_id}</td>
              <td>{new Date(c.inicio).toLocaleString('es-MX')}</td>
              <td>{c.estado}</td>
              <td>
                {permitidos(c).map((s) => (
                  <button key={s} onClick={() => cambiar(c.id, s)}>{s}</button>
                ))}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <Paginacion pagina={pagina} ultima={ultima} onCambiar={cargar} />
    </div>
  )
}

export default function App() {
  const [sesion, setSesion] = useState(() => JSON.parse(sessionStorage.getItem('sesion') || 'null'))
  const [vista, setVista] = useState('pacientes')

  function entrar(token, rol) {
    const s = { token, rol }
    sessionStorage.setItem('sesion', JSON.stringify(s))
    setSesion(s)
  }

  async function salir() {
    try { await api(sesion.token, '/logout', { method: 'POST' }) } catch { /* sin acción */ }
    sessionStorage.removeItem('sesion')
    setSesion(null)
  }

  if (!sesion) return <Login onLogin={entrar} />

  return (
    <div className="app">
      <header>
        <strong>Consultorio</strong>
        <button onClick={() => setVista('pacientes')}>Pacientes</button>
        <button onClick={() => setVista('citas')}>Citas</button>
        <span>Rol: {sesion.rol}</span>
        <button onClick={salir}>Salir</button>
      </header>
      {vista === 'pacientes' ? <Pacientes token={sesion.token} rol={sesion.rol} /> : <Citas token={sesion.token} rol={sesion.rol} />}
    </div>
  )
}