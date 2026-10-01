from pathlib import Path
from PIL import Image, ImageDraw
root=Path(__file__).resolve().parent.parent
out=root/'public/icons';out.mkdir(exist_ok=True)
scale=3
im=Image.new('RGB',(512*scale,512*scale)); d=ImageDraw.Draw(im)
for y in range(512*scale):
 t=y/(512*scale-1);d.line((0,y,512*scale,y),fill=(round(249-22*t),round(235-4*t),round(224+2*t)))
def points(seq):return [(int(x*scale),int(y*scale)) for x,y in seq]
d.ellipse((329*scale,133*scale,363*scale,167*scale),fill='#e5b56d')
d.polygon(points([(117,330),(229,158),(292,253),(337,197),(407,330)]),fill='#355561')
d.polygon(points([(166,253),(229,158),(264,211),(228,233),(204,211)]),fill='#fff5e8')
d.polygon(points([(310,232),(337,197),(365,249),(338,237)]),fill='#cee0dc')
d.line(points([(140,345),(390,345)]),fill='#bd735b',width=10*scale)
for name,size in [('icon-192.png',192),('icon-512.png',512),('icon-maskable-512.png',512),('apple-touch-icon.png',180)]:im.resize((size,size),Image.Resampling.LANCZOS).save(out/name)
print('Created 192px, 512px, maskable, and Apple app icons.')
