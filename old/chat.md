**Context:** I need to design a **real-time (or near real-time) group chatting system** for a **WordPress website** under a very tight deadline.The chat system will be used for **therapy session groups**.

**Core Requirements:**

1.  A **chat group must be created automatically** when an admin creates a new therapy session/event.
    
2.  When a user **registers for a therapy session**, they must be **automatically enrolled** into the corresponding chat group.
    
3.  Admins must be able to **manually add or remove users** from chat groups.
    
4.  All members of a group must be able to **chat without page reload** (near real-time is acceptable).
    
5.  Chat groups must have an **expiry date** and be **deleted or disabled automatically** after expiry.
    
6.  Prefer **free or low-cost solutions** that can be implemented quickly and are production-safe.
    

**Constraints & Preferences:**

*   WordPress is the primary platform (PHP + MySQL).
    
*   True WebSocket real-time is optional; **AJAX-based polling (1–5s delay)** is acceptable.
    
*   Must integrate with WordPress user authentication.
    
*   Avoid heavy custom development unless absolutely necessary.
    

**Task:** Do **NOT** write implementation code yet.

Please provide:

*   A **clear comparison of possible approaches**, including:
    
    *   WordPress plugin-based internal chat
        
    *   External chat platforms (Discord, Telegram, WhatsApp)
        
    *   Fully custom WordPress chat solution
        
*   Pros and cons of each approach for this use case
    
*   Recommendation of the **best approach given time, cost, and reliability**
    
*   High-level **system architecture plan** (components, data flow)
    
*   Suggested **plugins or platforms** if applicable
    
*   Clear reasoning for why the chosen approach is best
    

Assume implementation details will be discussed later.