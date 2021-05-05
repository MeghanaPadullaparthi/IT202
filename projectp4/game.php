<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tanks for Playing</title>
    <meta name="description" content="Tanks for Playing">
    <meta name="author" content="Matt Toegel">
</head>
<body>
<?php
include_once(__DIR__."/partials/nav.php");

if(is_logged_in()){
    //this will auto redirect if user isn't logged in
    $_SESSION["started"] = new DateTime();
    if(isset($_SESSION["outcome"])){
        unset($_SESSION["outcome"]);
    }
}
?>
<h2>Tanks for Playing</h2>
<p>Objective: Match must last at least 10 seconds. Winner is the last Tank alive.</p>
<p>Careful not to bump into the enemy tank.</p>
<!--Your canvas will come here -->
<canvas id="canvas" width="1024" height="512" style="border:1px solid lightgrey;">
    Your browser does not support the HTML5 canvas tag.
</canvas>
<hr/>
<small>Heavily based on this tutorial: <a href="https://spicyyoghurt.com/tutorials/html5-javascript-game-development/develop-a-html5-javascript-game">Here</a></small>
<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/3.1.9-1/core.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/3.1.9-1/md5.js"></script>
<script>
    "use strict";
    class GameObject{
        constructor (context, x, y, vx, vy, mass,restitution){
            this.context = context;
            this.x = x;
            this.y = y;
            this.vx = vx;
            this.vy = vy;
            this.dx = 0;
            this.dy = 0;
            this.mass = mass;
            this.restitution = restitution;
            this.disabled = false;

            this.isColliding = false;
        }

        getAngle(x1, x2, y1, y2) {
            // angle in radians
            //var angleRadians = Math.atan2(y2 - y1, x2 - x1);
            //return angleRadians;

            //angle in degrees
            var angleDeg = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
            return angleDeg;
        }
        transitionAngle(fromAngle, toAngle, speed) {
            // normalize the angles to 0-360 range
            const rad360 = 2*Math.PI;
            fromAngle = fromAngle % rad360;
            toAngle = toAngle % rad360;

            if (fromAngle < toAngle) {
                if (Math.abs(fromAngle - toAngle) < Math.PI)
                    fromAngle += speed;
                else fromAngle -= speed;
            }

            else {
                if (Math.abs(fromAngle - toAngle) < Math.PI)
                    fromAngle -= speed;
                else fromAngle += speed;
            }
            return fromAngle;
        }
    }
    class Tank extends GameObject
    {
        constructor (context, x, y, speed, isAI, range = 200, turnSpeed = 1, fireRate = 1, health = 10, damage = 1,
                     tankColor = "#05652D", barrelColor = "#034820", barrelTipColor = "#023417",
                     treadColor = "#000000", hitColor = "#A2082B"){
            //Pass params to super class
            super(context, x, y, speed, speed, 1, 0.9);
            //Set default width and height
            this.radius = 25;//mass > 0.5?25:10; //25;
            this.showAngle = true;
            this.angle = 0;
            //calc firerate
            const base = 10;//in seconds seconds
            if(fireRate < 0){
                fireRate = 0;
            }
            this.fireRate = base - Math.log(fireRate);
            //clamp it, although 10_000_000_000 or higher would be the only case
            if(this.fireRate < 0.1){
                this.fireRate = 0.1;
            }
            //move direction
            this.dx = 0;
            this.dy = 0;
            //target position
            this.tx = 0;
            this.ty = 0;
            this.range = range*range;//squared for performance
            this.shootArc = .5;
            this.desiredAngle = 0;
            this.bounceOfEdges = false;
            this.turnSpeed = turnSpeed;
            this.up = false;
            this.down = false;
            this.left = false;
            this.right = false;
            this.nextFire = 0;
            this.shooting = false;
            this.diameter = this.radius * 2;
            this.halfRadius = this.radius / 2;
            if(health < 1){
                health = 1;
            }
            this.totalHealth = health;
            this.currentHealth = this.totalHealth;
            this.isAI = isAI;
            this.tankColor = tankColor;
            this.barrelColor = barrelColor;
            this.hitColor = hitColor
            this.barrelTipColor = barrelTipColor;
            this.treadColor = treadColor;
            //calc damage (converts to base log of choice)
            //https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Math/log
            if(damage < 0){
                damage = 0;
            }
            //example math
            //log 5 = 0.69897000434
            //d 100 = 2.86
            //d 1000 = 4.29
            //d 10_000 = 5.72
            //d 100_000 = 7.15
            this.damage = 1 + ( Math.log(damage) / Math.log(5));
            if(this.isAI){
                this.atTarget = true;
            }
            else{
                this.atTarget = false;
            }
        }
        shoot(){
            if(this.context.game.time >= this.nextFire){
                this.nextFire = this.context.game.time + this.fireRate;
                this.context.game.spawnBullet(this.x, this.y, this.vx, this.vy, this.angle, this.range, this.damage);
            }
        }
        subDraw(){
            //tank body
            this.context.fillRect(this.x - this.radius, this.y - this.radius, this.diameter, this.diameter);
            //treads
            this.context.fillStyle = this.treadColor;
            this.context.fillRect(this.x - this.radius, this.y - this.radius * 1.1, this.halfRadius, this.diameter * 1.1);
            this.context.fillRect(this.x + this.halfRadius, this.y - this.radius * 1.1, this.halfRadius, this.diameter * 1.1);
            //tank barrel
            this.context.fillStyle = this.barrelColor;
            this.context.fillRect(this.x - (this.radius * .25), this.y-this.diameter, this.halfRadius, this.diameter);
            //tank tip
            this.context.fillStyle = this.barrelTipColor;
            this.context.fillRect(this.x - (this.radius*.25)-2, this.y-this.diameter, this.halfRadius + 4, this.halfRadius);
            this.context.moveTo(this.x, this.y);
            this.context.lineTo(this.x, this.y - 30);
            this.context.stroke();
        }
        lookAtDirection(){
            this.context.beginPath();
            this.context.fillStyle = this.isColliding ? this.hitColor : this.tankColor;
            this.context.translate(this.x, this.y);
            this.context.rotate(Math.PI / 180 * (this.angle + 90)); //increment the angle and rotate the image
            this.context.translate(-this.x, -this.y);
            this.subDraw();
            this.context.fill();
            this.context.setTransform(1, 0, 0, 1, 0, 0);


        }
        drawHealthbar(){
            //background
            this.context.fillStyle = "#000000";
            this.context.fillRect(this.x - this.radius, this.y - this.diameter, this.diameter, this.halfRadius);
            //fill
            this.context.fillStyle = "#ff0000";
            let p = (this.currentHealth / this.totalHealth);
            if(p <= 0){
                p = 0;
            }
            //console.log("Health Percent: ", p);
            this.context.fillRect(this.x - this.radius, this.y - this.diameter, this.diameter * p, this.halfRadius );
        }
        drawShotCooldown(){
            //background
            this.context.fillStyle = "#000000";
            this.context.fillRect(this.x - this.radius, this.y - this.diameter - 5, this.diameter, this.halfRadius);
            //fill
            this.context.fillStyle = "#0000ff";
            let diff =  Math.max(this.nextFire - this.context.game.time, 0);
            let p = (diff / this.fireRate);
            if(p <= 0){
                p = 0;
            }
            p = 1-p;
            //console.log("Health Percent: ", p);
            this.context.fillRect(this.x - this.radius, this.y - this.diameter - 5, this.diameter * p, this.halfRadius );
        }
        draw() {
            //Wrapped drawing logic in function for easy of use and later layering
            this.lookAtDirection();
            this.drawShotCooldown();
            this.drawHealthbar();
        }
        takeDamage(dmg){
            this.currentHealth -= dmg;
            if(this.currentHealth <= 0){
                console.log(this,"Died");
                this.disabled = true;
                this.context.game.died(this.isAI);
            }
        }
        doAI(){
            if(this.context.player.disabled){
                this.shooting = false;
                this.left = false;
                this.right = false;
                this.up = false;
                this.down = false;
                return;// We got'em
            }
            if(this.atTarget){
                //residual from other tests, can probably delete
                this.tx = this.context.player.x; //this.getRandomRange(0, this.context.canvas.width);
                this.ty = this.context.player.y;//this.getRandomRange(0, this.context.canvas.height);
                this.atTarget = false;
            }
            //set target x,y to player x,y
            this.tx = this.context.player.x;
            this.ty = this.context.player.y;
            this.desiredAngle = this.getAngle(this.x, this.tx, this.y, this.ty);

            const x2 = Math.pow((this.tx - this.x), 2);
            const y2 = Math.pow((this.ty - this.y), 2);
            const squareDistance = (x2 + y2);
            //convert my angle to 0-360 range
            const p = (this.angle % 360 + 360) % 360;
            //convert desired angle to 0-360 range
            this.desiredAngle = (this.desiredAngle % 360 + 360) % 360;
            //this seems to work well and doesn't flip rotation when it changes from - to + or + to -
            const abs = Math.abs(p - this.desiredAngle);
            if(abs <= this.shootArc){
                this.shooting = true;
            }
            else{
                this.shooting = false;
            }
            //AI Movement Controls
            //uses math to determine setting the direction booleans
            //left, right, up (just like the player)
            const halfPI = 180;
            this.left = false;
            this.right = false;
            if(p < this.desiredAngle){
                this.right = abs < halfPI;
                this.left = abs >= halfPI;
            }
            else{
                this.left = abs < halfPI;
                this.right = abs >= halfPI;
            }
            if(squareDistance < this.range){
                this.atTarget = true;
                this.tx = 0;
                this.ty = 0;
                this.up = false;
            }
            else{
                this.up = true;
            }
        }

        update(frameDeltaTime){
            if(this.disabled){
                return;

            }
            //frameDeltaTime is a bit of a lie here, it seems to be delta time (tutorial labeled wrong)
            //so we just add it to our local frameDeltaTime variable

            if(this.shooting){
                this.shoot();
            }
            if(this.isColliding){
                //this.takeDamage(1);
            }
            let g = 9.81 * 20;
            const canvasWidth = this.context.canvas.width;
            const canvasHeight = this.context.canvas.height;
            if(this.isAI){
                this.doAI();
            }
            //deal with rotation
            if(this.left){this.dx = -1;}
            if(this.right){this.dx = 1;}
            if(!this.left && !this.right){this.dx = 0;}

            //deal with movement
            if(this.up && !this.down){this.dy = 1;}
            if(this.down && !this.up){this.dy = -1;}
            if(!this.up && !this.down){this.dy = 0;}

            if(this.dx > 0){
                this.angle += this.turnSpeed * frameDeltaTime;

            }
            if(this.dx < 0){
                this.angle -= this.turnSpeed * frameDeltaTime;

            }
            if(this.dy == 0){
                return;//early termination, don't calc move if we aren't moving
            }
            //Move in direction of rotation (lookAt direction)
            let facingx = Math.cos(this.angle * Math.PI / 180);
            let facingy = Math.sin(this.angle * Math.PI / 180);
            //safety check since a previous mechanic in the GameWorld caused NaN values
            if(isNaN(this.dx) || isNaN(this.dy) || isNaN(this.vx) || isNaN(this.vy)){
                return;
            }
            const vx = this.vx * facingx * frameDeltaTime;
            const vy = this.vy * facingy * frameDeltaTime;
            //apply the move if we're meant to move
            if (this.dy > 0) {
                this.x += vx;
                this.y += vy;
            }
            else if (this.dy < 0) {
                this.x -= vx;
                this.y -= vy;
            }
        }
    }
    class Circle extends GameObject
    {
        constructor (context, x, y, vx, vy, radius, showAngle, bounceOfEdges){
            //Pass params to super class
            super(context, x, y, vx, vy, 1, 0.9);

            //Set default width and height
            this.radius = radius;
            this.showAngle = true;
            this.bounceOfEdges = false;
            this.angle = 0;
        }

        draw() {
            if(this.disabled){
                return;
            }
            //Draw a simple square
            this.context.fillStyle = this.isColliding ? '#ff8080' : '#0099b0';

            this.context.beginPath();
            this.context.arc(this.x, this.y, this.radius, 0, 2 * Math.PI);
            this.context.fill();

            if (this.showAngle) {
                this.frameCenterX = (this.x - this.radius) + (this.radius * 2) / 2; //(this.column * this.frameWidth) + this.frameWidth / 2;
                this.frameCenterY = (this.y - this.radius) + (this.radius * 2) / 2; //this.frameHeight / 2; //(this.row * this.frameHeight) + this.frameHeight / 2;

                //this.context.save();
                this.context.translate(this.frameCenterX, this.frameCenterY); //let's translate
                this.context.rotate(Math.PI / 180 * (this.angle + 90)); //increment the angle and rotate the image
                this.context.translate(-this.frameCenterX, -this.frameCenterY);

                this.context.beginPath();
                this.context.moveTo(this.x, this.y);
                this.context.lineTo(this.x, this.y - 30);
                this.context.stroke();

                // Reset transformation matrix to the identity matrix
                this.context.setTransform(1, 0, 0, 1, 0, 0);
                // this.context.restore();
            }
        }


        update(frameDeltaTime){
            if(this.disabled){
                return;
            }
            let g = 9.81 * 20;//gravity times modifier
            //find canvas size again in case of resize
            const canvasWidth = this.context.canvas.width;
            const canvasHeight = this.context.canvas.height;

            if (this.bounceOfEdges){
                // Apply g acceleration
                this.vy += g * frameDeltaTime;
            }

            //Move with velocity x/y
            this.x += this.vx * frameDeltaTime;
            this.y += this.vy * frameDeltaTime;

            if (this.showAngle){
                let angleRadians = Math.atan2(this.vy, this.vx); //in radians
                let degrees = 180*angleRadians/Math.PI; // to degrees
                this.angle =  degrees; //(360+Math.round(degrees))%360;
            }
        }
    }
    class Bullet extends Circle{
        constructor (context, x, y, vx, vy, radius, type=3){
            if(type == 3){
                radius *= .5;
            }
            //Pass params to super class
            super(context, x, y, vx, vy, radius, false, false);
            this.damage = 1;
            this.type = type
            this.halfRadius = this.radius * .5;
            this.diameter = this.radius * 2;
        }
        setup(x, y, vx, vy, dist){
            this.x = x;
            this.y = y;
            this.sx = x;
            this.sy = y;
            this.vx = vx;
            this.vy = vy;
            this.dist = dist;
            console.log("setup");
        }
        subDraw(){
            //change bullet display based on type
            if(this.type == 1){
                this.context.beginPath();
                this.context.arc(this.x, this.y, this.radius, 0, 2 * Math.PI);
                this.context.fill();
            }
            else if(this.type == 2){
                this.context.beginPath();
                this.context.arc(this.x, this.y, this.radius, Math.PI, 0);
                this.context.fillRect(this.x-this.radius, this.y+this.halfRadius, this.diameter, this.diameter);
                this.context.fill();
            }
            else if(this.type==3){
                this.context.beginPath();
                this.context.arc(this.x, this.y, this.radius, 0, 2 * Math.PI);
                this.context.fill();
            }
        }
        lookAtDirection(){
            this.context.beginPath();
            this.context.fillStyle = this.isColliding ? '#000000' : '#404040';
            this.context.translate(this.x, this.y);
            this.context.rotate(Math.PI / 180 * (this.angle + 90)); //increment the angle and rotate the image
            this.context.translate(-this.x, -this.y);
            this.subDraw();
            this.context.fill();
            this.context.setTransform(1, 0, 0, 1, 0, 0);

        }
        draw() {
            if(this.disabled){
                return;
            }
            this.lookAtDirection();
        }
        update(frameDeltaTime){
            if(this.disabled){
                return;
            }
            //Move with velocity x/y
            this.x += this.vx * frameDeltaTime;
            this.y += this.vy * frameDeltaTime;

            if (this.showAngle){
                let angleRadians = Math.atan2(this.vy, this.vx); //in radians
                let degrees = 180*angleRadians/Math.PI; // to degrees
                this.angle =  degrees; //(360+Math.round(degrees))%360;
            }
            const x2 = Math.pow((this.sx - this.x), 2);
            const y2 = Math.pow((this.sy - this.y), 2);
            const squareDistance = (x2 + y2);
            //disable bullet if it travels greater than range
            if(squareDistance > this.dist){
                console.log("out of range");
                this.disabled = true;
            }
        }
    }

    /*class Square extends GameObject
    {
        constructor (context, x, y, vx, vy, mass){
            //Pass params to super class
            super(context, x, y, vx, vy, mass, 1);

            //Set default width and height
            this.width = 50;
            this.height = 50;
        }

        draw(){
            //Draw a simple square
            this.context.fillStyle = this.isColliding?'#ff8080':'#0099b0';
            this.context.fillRect(this.x, this.y, this.width, this.height);
        }

        update(frameDeltaTime){

            if (this.gravityAndMass){
                this.vy += (9.81 * 3) * frameDeltaTime;
            }

            //Move with velocity x/y
            this.x += this.vx * frameDeltaTime;
            this.y += this.vy * frameDeltaTime;
        }
    }*/
    const Keys = {
        W : 87,
        S : 83,
        A : 65,
        D : 68,
        SPACE : 32,
        UP : 38,
        DOWN : 40,
        LEFT : 37,
        RIGHT : 39
    };
    class GameWorld {
        // Trigger init function when the page has loaded

        constructor(showCollision, showCircles, bounce, gravityAndMass, showAngle, bounceOfEdges) {
            this.canvas = null;
            this.context = null;
            this.oldTimeStamp = 0;
            this.gameObjects = [];
            this.resetCounter = 0;
            this.showCollision = showCollision;
            this.showCircles = showCircles;
            this.bounce = bounce;
            this.gravityAndMass = gravityAndMass;
            this.showAngle = showAngle;
            this.bounceOfEdges = bounceOfEdges;
            this.gameOver = false;
            this.time = 0;
            //this.contxt. player = null;
        }
        listen(){
            if(this.context.player != null){
                let self = this;
                window.addEventListener("keyup", function(e){
                    if ( [Keys.W, Keys.UP].includes(e.keyCode)) {
                        self.context.player.up = false;
                    }

                    if ( [Keys.S, Keys.DOWN].includes(e.keyCode) ) {
                        self.context.player.down = false;
                    }

                    if ( [Keys.A, Keys.LEFT].includes(e.keyCode) ) {
                        //self.context.player.dx = 0;
                        self.context.player.left = false;
                    }

                    if ( [Keys.D, Keys.RIGHT].includes(e.keyCode) ) {
                        //self.context.player.dx = 0;
                        self.context.player.right = false;
                    }

                    if(e.keyCode == Keys.SPACE){
                        self.context.player.shooting = false;
                    }
                });
                var down = function(e){
                    if ( [Keys.W, Keys.UP].includes(e.keyCode) ) {
                        self.context.player.up = true;
                    }

                    if ( [Keys.S, Keys.DOWN].includes(e.keyCode)) {
                        self.context.player.down = true;
                    }

                    if ( [Keys.A, Keys.LEFT].includes(e.keyCode) ) {
                        //self.context.player.dx = -1;
                        self.context.player.left = true;
                    }

                    if ( [Keys.D, Keys.RIGHT].includes(e.keyCode) ) {
                        //self.context.player.dx = 1;
                        self.context.player.right = true;
                    }

                    if(e.keyCode == Keys.SPACE){
                        self.context.player.shooting = true;
                    }
                };

                window.addEventListener("keydown", down);
                window.addEventListener("keypress", down);
            }
        }
        init(canvasId, tanks) {
            this.canvas = document.getElementById(canvasId);
            this.context = this.canvas.getContext('2d');
            this.tanks = tanks;
            this.createWorld();

            // Request an animation frame for the first time
            // The gameLoop() function will be called as a callback of this request

            window.requestAnimationFrame((timeStamp) => this.gameLoop(timeStamp));
        }

        createWorld() {
            this.context.game = this;
            //set player start position on second half of canvas
            let px = getRandomRange(this.canvas.width*.5, this.canvas.width);
            let py = getRandomRange(0, this.canvas.height);
            //set AI start position on first half of canvas
            let ax = getRandomRange(0, this.canvas.width*.5);
            let ay = getRandomRange(0, this.canvas.height);
            
            let ptdata = this.tanks[0];
            //setup player tank
            let pt = new Tank(this.context, px, py, ptdata.speed, false, ptdata.range,
                ptdata.turnSpeed, ptdata.fireRate, ptdata.health, ptdata.damage, ptdata.tankColor,
                ptdata.barrelColor, ptdata.barrelTipColor, ptdata.treadColor, ptdata.hitColor);
            let etdata = this.tanks[1];
            //setup enemy tank
            let et = new Tank(this.context, ax, ay, etdata.speed, true, etdata.range,
                etdata.turnSpeed, etdata.fireRate, etdata.health, etdata.damage, etdata.tankColor,
                etdata.barrelColor, etdata.barrelTipColor, etdata.treadColor, etdata.hitColor);
            console.log(ptdata);
            console.log(etdata);
            this.pt = pt;
            this.et = et;
            this.gameObjects = [
                pt,
                et
            ];

            this.context.player = this.gameObjects[0];
            this.listen();
        }
        died(isAI){
            if(!this.gameOver){
                //if it's AI that died, we won
                saveScore(isAI?"win":"loss");
            }
        }
        spawnBullet(x, y, vx, vy, angle, dist, damage){
            let bullet;
            //use object pooling and find a disabled bullet to spawn/reactivate
            for(let i = 0; i < this.gameObjects.length; i++){
                let g = this.gameObjects[i];
                if(g instanceof Bullet){
                    if(g.disabled){
                        bullet = g;
                        break;
                    }
                }
            }
            //if nothing was available extend the pool
            if(!bullet){
                bullet = new Bullet(this.context, 300, 75, 50, 50, 5);
                this.gameObjects.push(bullet);
            }
            const vectorX = Math.cos(angle * Math.PI / 180) ;
            const vectorY = Math.sin(angle * Math.PI / 180) ;
            const _x = x + vectorX * 30 * 1.5;
            const _y = y + vectorY * 30 * 1.5;
            bullet.setup(_x, _y, vectorX * (150+vx), vectorY* (150+vy), dist);
            bullet.damage = damage;
            bullet.disabled = false;
        }

        gameLoop(timeStamp) {

            // Calculate how much time has passed
            let frameDeltaTime = (timeStamp - this.oldTimeStamp) / 1000;
            this.oldTimeStamp = timeStamp;
            //set a minimum deltaTime (honestly forget why :/)
            frameDeltaTime = Math.min(frameDeltaTime, 0.1);
            this.time += frameDeltaTime;
            //update all gameobjects
            for (let i = 0; i < this.gameObjects.length; i++) {
                this.gameObjects[i].update(frameDeltaTime);
            }
            //do collisions
            if (this.showCollision) {
                this.detectCollisions(frameDeltaTime);
            }
            //erase
            this.clearCanvas();
            //paint objects
            for (let i = 0; i < this.gameObjects.length; i++) {
                this.gameObjects[i].draw();
            }
            //paint time
            this.drawTime();
            // The loop function has reached it's end
            // Keep requesting new frames
            window.requestAnimationFrame((timeStamp) => this.gameLoop(timeStamp));
        }
        destroyPlayer(obj){
            if(obj instanceof Tank && !obj.isAI){
                obj.takeDamage(obj.totalHealth);
            }
        }
        detectCollisions(frameDeltaTime) {
            var obj1;
            var obj2;
            for (var i = 0; i < this.gameObjects.length; i++) {
                obj1 = this.gameObjects[i];
                obj1.isColliding = false;
                if(obj1.disabled){
                    continue;
                }
                const canvasWidth = this.context.canvas.width;
                const canvasHeight = this.context.canvas.height;
                if (obj1.x < obj1.radius) {
                    obj1.x = obj1.radius;
                    obj1.isColliding = true;
                } else if (obj1.x > canvasWidth - obj1.radius) {
                    obj1.x = canvasWidth - obj1.radius;
                    obj1.isColliding = true;
                }
                if (obj1.y < obj1.radius) {
                    obj1.y = obj1.radius;
                    obj1.isColliding = true;
                } else if (obj1.y > canvasHeight - obj1.radius) {
                    obj1.y = canvasHeight - obj1.radius;
                    obj1.isColliding = true;
                }
                //if a bullet hit, disable it so we don't keep colliding
                if(obj1 instanceof Bullet && obj1.isColliding){
                    obj1.disabled = true;
                }

            }

            for (var i = 0; i < this.gameObjects.length; i++) {
                obj1 = this.gameObjects[i];
                if(obj1.disabled){
                    continue;
                }
                for (var j = i + 1; j < this.gameObjects.length; j++) {
                    obj2 = this.gameObjects[j];
                    if(obj2.disabled){
                        continue;
                    }
                    let collided = false;
                    //don't really care how they collided, just check
                    if (this.showCircles) {
                        if (this.circleIntersect(obj1.x, obj1.y, obj1.radius, obj2.x, obj2.y, obj2.radius)) {
                            collided = true;
                        }
                    } else {
                        if (this.rectIntersect(obj1.x, obj1.y, obj1.width, obj1.height, obj2.x, obj2.y, obj2.width, obj2.height)) {
                            collided = true;
                        }
                    }
                    //ok lets do the logic
                    if(collided){
                        obj1.isColliding = true;
                        obj2.isColliding = true;
                        //TODO check if both are bullets? - Think I'll keep it as an interesting game mechanic
                        //lets you block with your bullets
                        if(obj1 instanceof Bullet){
                            obj1.disabled = true;//cleanup used bullet
                            if(obj2 instanceof Tank){
                                obj2.takeDamage(obj1.damage);
                            }
                        }
                        if(obj2 instanceof Bullet){
                            obj2.disabled = true;//cleanup used bullet
                            if(obj1 instanceof Tank){
                                obj1.takeDamage(obj2.damage);
                            }
                        }
                        //prevent collision of tanks; the lazily prevents phasing through the AI to dodge
                        if(obj1 instanceof Tank && obj2 instanceof Tank){
                            //if one of these is a player it'll be destroyed
                            this.destroyPlayer(obj1);
                            this.destroyPlayer(obj2);
                        }
                    }
                }
            }
        }
        getDistance(x1, y1, x2, y2){
            return Math.sqrt((x2-x1)*(x2-x1) + (y2-y1)*(y2-y1));
        }


        circleIntersect(x1, y1, r1, x2, y2, r2) {

            // Calculate the distance between the two circles
            var distance = (x2-x1)*(x2-x1) + (y2-y1)*(y2-y1);

            // When the distance is smaller or equal to the sum
            // of the two radius, the circles overlap
            return distance <= ((r1 + r2) * (r1 + r2))
        }

        rectIntersect(x1, y1, w1, h1, x2, y2, w2, h2) {

            // Check x and y for overlap
            if (x2 > w1 + x1 || x1 > w2 + x2 || y2 > h1 + y1 || y1 > h2 + y2){
                return false;
            }

            return true;
        }

        clearCanvas() {
            // Clear the canvas
            this.context.clearRect(0, 0, this.canvas.width, this.canvas.height);
        }

        drawTime(){
            this.context.font = "30px Arial";
            this.context.fillStyle = "#000000";
            this.context.fillText("Time: " + Math.round(this.time), 10, 50);
        }
    }
    let gameWorld;
    function extractTankData(){
        let ptd = {
            "h": gameWorld.pt.currentHealth,
            "th": gameWorld.pt.totalHealth,
            "d": gameWorld.pt.damage,
            "s": gameWorld.pt.speed,
            "ts": gameWorld.pt.turnSpeed,
            "r:": gameWorld.pt.range,
            "x": gameWorld.pt.x,
            "y": gameWorld.pt.y,
            "fr": gameWorld.pt.fireRate,
            "ra": gameWorld.pt.radius
        }
        let etd = {
            "h": gameWorld.et.currentHealth,
            "th": gameWorld.et.totalHealth,
            "d": gameWorld.et.damage,
            "s": gameWorld.et.speed,
            "ts": gameWorld.et.turnSpeed,
            "r:": gameWorld.et.range,
            "x": gameWorld.et.x,
            "y": gameWorld.et.y,
            "fr": gameWorld.et.fireRate,
            "ra": gameWorld.et.radius
        }
        ptd = JSON.stringify(ptd);
        etd = JSON.stringify(etd);
        snapshot.push([ptd, etd, CryptoJS.MD5(ptd+etd).toString()]);
    }
    function setupGame(canvasId, tanks){
        gameWorld = new GameWorld(true, true, false, false, true, false);
        gameWorld.init(canvasId, tanks);
        window.setInterval(function(){
            extractTankData();
        }, 5000);
    }
    let snapshot = [];
</script>
<script>
    function getRandomRange(min, max) {
        return Math.random() * (max - min) + min;
    }
    function getGameData(){
        var xhttp = new XMLHttpRequest();
        xhttp.onload = function() {
            if (xhttp.status != 200) { // analyze HTTP status of the response
                console.log(`Error ${xhttp.status}: ${xhttp.statusText}`); // e.g. 404: Not Found
            } else { // show the result
                console.log(`Done, got ${xhttp.response.length} bytes`); // response is the server
                console.log(xhttp.responseText);
                let json = JSON.parse(xhttp.responseText);
                console.log(json);
                //start game
                setupGame('canvas', json.tanks);
                //init('canvas', true, true, true, false, true, true);
            }
        };
        xhttp.onerror = function() {
            console.log("Request failed");
        };
        xhttp.open("GET", "api/load_tanks.php", true);
        xhttp.send();
    }
    function saveScore(gameState){
        var xhttp = new XMLHttpRequest();
        window.onbeforeunload = undefined;
        extractTankData();
        xhttp.onload = function() {
            if (xhttp.status != 200) { // analyze HTTP status of the response
                console.log(`Error ${xhttp.status}: ${xhttp.statusText}`); // e.g. 404: Not Found
                alert("Something went wrong, the activity of this play may have been lost");
            } else { // show the result
                console.log(xhttp.responseText);
                console.log(`Done, got ${xhttp.response.length} bytes`); // response is the server
                window.location.replace("outcome.php");
            }
        };
        xhttp.onerror = function() {
            alert("Request failed");
        };

        xhttp.open("POST", "api/save_score.php", true);
        //This header is necessary when doing POST and must come after Open and before Send
        xhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xhttp.send("score=1&outcome=" + gameState + "&data=" + JSON.stringify(snapshot));
    }
    window.onbeforeunload = function(){
        //if user navigates away count it as a loss
        saveScore("loss");
    }
    window.onload = function(){
        //ajax call to get game data from server
        //fetch player tank
        //fetch enemy tank
        //canvasId, showCollision, showCircles, bounce, gravityAndMass, showAngle, bounceOfEdges
        //init('canvas', true, true, true, false, true, true);
        getGameData();
        console.log("init");
    };


</script>
</body>
</html>