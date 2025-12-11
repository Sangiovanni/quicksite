


document.addEventListener("DOMContentLoaded", function() {
    const blocks = document.querySelectorAll('.scrolling-block');
    let lastScrollY = window.scrollY; // Store the last scroll position

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            const currentScrollY = window.scrollY;
            const isScrollingDown = currentScrollY > lastScrollY;

            // Check if the element is in the viewport
            if (entry.isIntersecting) {
                // If the element is entering from the bottom (scrolling down)
                // or if it's already in view and we just passed the 'go away' point
                
                  entry.target.classList.add('is-visible');
                  entry.target.classList.remove('go-away');
                
            } else {
                // The element is not intersecting.
                // We want it to "go away" only if it's scrolling past the top.
                // We can check if the top of the element is above a certain point in the viewport
                
                entry.target.classList.add('go-away');
                entry.target.classList.remove('is-visible');
                
            }
        });
        // Update the last scroll position
        lastScrollY = window.scrollY;
    }, {
        // We set a threshold of 0.1, which means the callback fires when 10% of the element is visible.
        // We also need a threshold of 0.0 to detect when it's completely out of view.
        threshold: [0.25]
    });

    blocks.forEach(block => {
        observer.observe(block);
    });
});