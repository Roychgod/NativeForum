#!/usr/bin/env python3
"""Build assets/presets/<name>.css = src/<name>.tokens.css + src/_skin.css"""
import os, glob
d=os.path.dirname(os.path.abspath(__file__)); out=os.path.dirname(d)
skin=open(os.path.join(d,'_skin.css')).read()
for f in sorted(glob.glob(os.path.join(d,'*.tokens.css'))):
    name=os.path.basename(f)[:-len('.tokens.css')]
    open(os.path.join(out,name+'.css'),'w').write(open(f).read().rstrip()+"\n\n"+skin)
    print('built',name)
