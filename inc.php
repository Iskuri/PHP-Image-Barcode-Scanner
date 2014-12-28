<?php

foreach(scandir("classes/") as $class) {
    
    if($class != "." && $class != "..") {
        include("classes/".$class);
    }
    
}
