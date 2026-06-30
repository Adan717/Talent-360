import os
import sys
import json
import requests
 
# 1. Asegurar que las dependencias de video e imagen estén instaladas
def install_requirements():
    try:
        import moviepy
        import gtts
        import PIL
    except ImportError:
        print("Instalando dependencias de video e imagen (moviepy, gtts, pillow) en caliente...")
        import subprocess
        subprocess.check_call([sys.executable, "-m", "pip", "install", "moviepy", "gTTS", "Pillow"])
        print("Dependencias instaladas con éxito.")
 
install_requirements()
 
from gtts import gTTS
from PIL import Image, ImageDraw, ImageFont
from moviepy.editor import ImageSequenceClip, AudioFileClip
 
def generate_audio(text, output_audio_path):
    """
    Genera la locución por IA.
    Utiliza ElevenLabs si la API Key está en las variables de entorno,
    de lo contrario cae en Google Text-To-Speech (gTTS) como fallback gratuito.
    """
    eleven_key = os.environ.get("ELEVENLABS_API_KEY")
    voice_id = os.environ.get("ELEVENLABS_VOICE_ID", "21m00Tcm4TlvDq8ikWAM") # Rachel Default Voice
 
    if eleven_key:
        print("Generando locución profesional con ElevenLabs API...")
        url = f"https://api.elevenlabs.io/v1/text-to-speech/{voice_id}"
        headers = {
            "Accept": "audio/mpeg",
            "Content-Type": "application/json",
            "xi-api-key": eleven_key
        }
        data = {
            "text": text,
            "model_id": "eleven_monolingual_v1",
            "voice_settings": {
                "stability": 0.75,
                "similarity_boost": 0.75
            }
        }
        response = requests.post(url, json=data, headers=headers)
        if response.status_code == 200:
            with open(output_audio_path, "wb") as f:
                f.write(response.content)
            print("Audio de ElevenLabs guardado exitosamente.")
            return True
        else:
            print(f"Fallo ElevenLabs ({response.status_code}): {response.text}. Usando gTTS de respaldo...")
 
    print("Generando audio con Google TTS (gTTS)...")
    tts = gTTS(text=text, lang='es')
    tts.save(output_audio_path)
    print("Audio de gTTS guardado con éxito.")
    return True
 
def draw_wrapped_text(draw, text, font, color, max_width, start_y, width):
    """
    Pinta texto ajustando saltos de línea de forma manual sobre la imagen.
    """
    words = text.split()
    lines = []
    current_line = []
    
    for word in words:
        current_line.append(word)
        # Medir ancho de la línea actual
        line_str = " ".join(current_line)
        bbox = draw.textbbox((0, 0), line_str, font=font)
        line_w = bbox[2] - bbox[0]
        if line_w > max_width:
            current_line.pop()
            lines.append(" ".join(current_line))
            current_line = [word]
            
    if current_line:
        lines.append(" ".join(current_line))
        
    y = start_y
    for line in lines:
        bbox = draw.textbbox((0, 0), line, font=font)
        w = bbox[2] - bbox[0]
        h = bbox[3] - bbox[1]
        x = (width - w) / 2
        draw.text((x, y), line, font=font, fill=color)
        y += h + 20
 
def render_promo_video(campaign_text, output_video_path, width=1080, height=1920):
    """
    Crea un video vertical (9:16) con fondo morado brillante de DecorArte,
    locución por IA y subtítulos dinámicos renderizados de forma segura usando Pillow.
    No requiere ImageMagick.
    """
    temp_audio = "temp_locucion.mp3"
    temp_dir = "temp_frames"
    os.makedirs(temp_dir, exist_ok=True)
    
    # 1. Generar la locución del video
    generate_audio(campaign_text, temp_audio)
    
    # 2. Cargar el clip de audio para medir la duración del video
    audio_clip = AudioFileClip(temp_audio)
    duration = audio_clip.duration
    
    # 3. Dividir el texto en bloques para los subtítulos animados
    words = campaign_text.split()
    words_per_block = 5
    text_blocks = []
    for i in range(0, len(words), words_per_block):
        text_blocks.append(" ".join(words[i:i + words_per_block]))
        
    fps = 24
    total_frames = int(duration * fps)
    frames_per_block = total_frames / len(text_blocks)
    
    # Crear fuente por defecto o Arial si está disponible en Windows
    try:
        font = ImageFont.truetype("arial.ttf", 60)
    except IOError:
        font = ImageFont.load_default()
        
    frame_files = []
    
    print("Renderizando frames con Pillow (sin requerir ImageMagick)...")
    for frame_idx in range(total_frames):
        # 1. Crear fondo morado corporativo de DecorArte (#8b5cf6 = RGB [139, 92, 246])
        img = Image.new("RGB", (width, height), color=(139, 92, 246))
        draw = ImageDraw.Draw(img)
        
        # 2. Determinar qué bloque de texto mostrar
        block_idx = int(frame_idx / frames_per_block)
        if block_idx >= len(text_blocks):
            block_idx = len(text_blocks) - 1
            
        current_text = text_blocks[block_idx]
        
        # Pintar el texto
        draw_wrapped_text(draw, current_text, font, (255, 255, 255), width - 150, height / 2 - 100, width)
        
        # Guardar frame temporal
        frame_path = os.path.join(temp_dir, f"frame_{frame_idx:05d}.jpg")
        img.save(frame_path)
        frame_files.append(frame_path)
        
    # 4. Crear el video a partir de la secuencia de imágenes
    print("Construyendo clip de video...")
    video_clip = ImageSequenceClip(frame_files, fps=fps)
    video_clip = video_clip.set_audio(audio_clip)
    
    # 5. Exportar a MP4
    print(f"Exportando video a {output_video_path}...")
    video_clip.write_videofile(
        output_video_path,
        fps=fps,
        codec="libx264",
        audio_codec="aac",
        temp_audiofile="temp_rendering.m4a",
        remove_temp=True
    )
    
    # 6. Limpieza de archivos temporales
    audio_clip.close()
    video_clip.close()
    if os.path.exists(temp_audio):
        os.remove(temp_audio)
        
    for f in frame_files:
        try:
            os.remove(f)
        except Exception:
            pass
            
    try:
        os.rmdir(temp_dir)
    except Exception:
        pass
        
    print("¡Video promocional de DecorArte exportado con éxito total!")
 
if __name__ == "__main__":
    guion_sat_2027 = (
        "Atención empresarios en México. A partir de 2027, el registro digital de asistencia "
        "será obligatorio por el SAT. Con Talent 360 blindas tu sucursal hoy mismo con reloj checador GPS "
        "y reportes listos para Hacienda en un solo click. Visita talent360.com y pruébalo gratis."
    )
    
    output_path = "C:\\Users\\Servidor\\Desktop\\Talent360\\scripts\\campana_sat_2027.mp4"
    
    # Crear carpeta scripts si no existe
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    
    render_promo_video(guion_sat_2027, output_path)
